import app from 'flarum/forum/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import m from 'mithril';
import type ChirpState from '../ChirpState';

interface ChirpDockAttrs extends ComponentAttrs {
  state: ChirpState;
}

/**
 * The "still listening" dock: mounted outside Flarum's SPA root, so once
 * you're in a room the audio and its controls survive navigation — browse the
 * index, read another thread, keep listening. It hides itself on the
 * discussion you're listening to, where the in-page bar already has the
 * controls (and says more).
 */
export default class ChirpDock extends Component<ChirpDockAttrs> {
  private lastVisible: boolean | null = null;
  private watching = false;

  /** When the room bar left the screen — the dock waits this shudder out so
   *  route transitions (bar unmounts, new page's bar mounts a beat later)
   *  don't flash it. Hide stays instant. */
  private barGoneSince: number | null = null;
  private wakePending = false;

  private static readonly SHOW_DELAY_MS = 600;

  oncreate(vnode: Mithril.VnodeDOM<ChirpDockAttrs>) {
    super.oncreate(vnode);
    this.watch();
  }

  /**
   * Scrolling doesn't redraw Mithril, so watch for the room toolbar entering
   * or leaving the viewport and redraw only when the verdict actually flips.
   */
  private watch(): void {
    if (this.watching) return;
    this.watching = true;

    let queued = false;
    const check = () => {
      if (queued) return;
      queued = true;
      requestAnimationFrame(() => {
        queued = false;
        const visible = this.roomBarOnScreen();
        if (visible !== this.lastVisible) {
          this.lastVisible = visible;
          m.redraw();
        }
      });
    };

    window.addEventListener('scroll', check, { passive: true });
    window.addEventListener('resize', check, { passive: true });
  }

  private roomBarOnScreen(): boolean {
    const state = this.attrs.state;
    if (!state.discussionId) return false;

    return Array.from(document.querySelectorAll(`.ChirpBar[data-chirp-room="${state.discussionId}"]`)).some((el) => {
      const node = el as HTMLElement;
      const r = node.getBoundingClientRect();
      // ⚠️ NOT offsetParent — it is null for position:fixed elements, which
      // is exactly what the bar IS on phones, so the dock believed the bar
      // was never on screen and overlapped it. Computed style + a real rect
      // covers fixed bars and still excludes Flarum's hidden list pane
      // (display:none collapses the rect to zero).
      const style = getComputedStyle(node);
      return style.display !== 'none' && style.visibility !== 'hidden' && r.width > 0 && r.height > 0 && r.bottom > 0 && r.top < window.innerHeight;
    });
  }

  view(): Mithril.Children {
    const t = (k: string, d?: any) => app.translator.trans('linkrobins-chirp.forum.' + k, d);
    const { state } = this.attrs;

    if (!state.connected()) {
      this.barGoneSince = null;
      return null;
    }

    // Stand down wherever the room's own toolbar is actually VISIBLE — its
    // discussion page, or its row in the list. Presence isn't enough: Flarum
    // keeps the discussion-list pane mounted (but hidden) while you read a
    // discussion, and a hidden row would otherwise suppress the dock. Scroll
    // the row out of view and the dock takes over.
    if (this.roomBarOnScreen()) {
      this.barGoneSince = null;
      return null;
    }

    // Bar just left the screen: hold off before appearing, so a route
    // transition's bar-unmount → bar-remount doesn't flash the dock.
    if (this.barGoneSince === null) this.barGoneSince = Date.now();
    if (Date.now() - this.barGoneSince < ChirpDock.SHOW_DELAY_MS) {
      if (!this.wakePending) {
        this.wakePending = true;
        setTimeout(() => {
          this.wakePending = false;
          m.redraw();
        }, ChirpDock.SHOW_DELAY_MS + 50);
      }
      return null;
    }

    const speakers = state.speakers();
    const mode = (app.store.getById('discussions', String(state.discussionId))?.attribute?.('chirpRoomMode') as string) || 'live';

    return m('.ChirpDock', { className: state.anyoneSpeaking ? 'is-active' : '' }, [
      m('.ChirpDock-main', [
        mode === 'persistent' ? m('span.ChirpBadge.ChirpBadge--voice', t('voice_badge')) : m('span.ChirpBadge', t('live_badge')),
        m(
          '.ChirpWave',
          { 'aria-hidden': 'true' },
          Array.from({ length: 8 }, (_, i) => m('span.ChirpWave-bar', { style: { animationDelay: `${(i % 5) * 0.1}s` } }))
        ),
        state.roomPath
          ? m('a.ChirpDock-title', { href: state.roomPath, oncreate: m.route.link, title: state.roomTitle }, state.roomTitle)
          : m('span.ChirpDock-title', state.roomTitle),
      ]),
      m('.ChirpDock-actions', [
        speakers.length ? m('span.ChirpDock-count', String(state.listenerCount())) : null,
        state.canPublish
          ? m(
              Button,
              {
                className: `Button Button--size-sm ChirpBar-btn ${state.muted ? 'is-muted' : ''}`,
                icon: state.muted ? 'fas fa-microphone-slash' : 'fas fa-microphone',
                onclick: () => state.setMuted(!state.muted),
              },
              state.muted ? t('unmute') : t('mute')
            )
          : null,
        m(
          Button,
          {
            className: 'Button Button--size-sm ChirpBar-btn',
            icon: 'fas fa-arrow-right-from-bracket',
            onclick: () => state.leave(),
          },
          t('leave')
        ),
      ]),
    ]);
  }
}
