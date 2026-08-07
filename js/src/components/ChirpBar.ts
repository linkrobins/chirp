import app from 'flarum/forum/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Tooltip from 'flarum/common/components/Tooltip';
import type Mithril from 'mithril';
import m from 'mithril';
import type ChirpState from '../ChirpState';

interface ChirpBarAttrs extends ComponentAttrs {
  discussion: any;
  state: ChirpState;
}

const WAVE_BARS = 14;

/**
 * The live-room bar in the discussion hero. Shows the room, not just its
 * existence: a waveform that only dances while someone is actually talking
 * (LiveKit's active-speaker detection), the stage as avatars with a speaking
 * ring and muted state, the audience count, and the controls for whoever
 * you are. The thread below is still the chat — this stays one strip.
 */
export default class ChirpBar extends Component<ChirpBarAttrs> {
  // Phones dock the bar over the content, so the page needs bottom padding
  // while one is mounted (see forum.less).
  private composerWatch?: ResizeObserver;

  oncreate(vnode: Mithril.VnodeDOM<ChirpBarAttrs>) {
    super.oncreate(vnode);
    document.documentElement.classList.add('chirp-live');
    this.trackComposer();
  }

  onupdate(vnode: Mithril.VnodeDOM<ChirpBarAttrs>) {
    super.onupdate(vnode);
    this.trackComposer();
  }

  onremove(vnode: Mithril.VnodeDOM<ChirpBarAttrs>) {
    super.onremove(vnode);
    document.documentElement.classList.remove('chirp-live', 'chirp-composer-full');
    document.documentElement.style.removeProperty('--chirp-composer-h');
    this.composerWatch?.disconnect();
    this.composerWatch = undefined;
  }

  /**
   * On phones the bar is docked to the bottom — exactly where Flarum's
   * composer lives. Publish the composer's height as --chirp-composer-h so
   * the bar rides above it instead of being covered, and step aside entirely
   * once the composer takes over most of the screen (writing a reply).
   * A ResizeObserver keeps this true through the open/minimise animations,
   * which finish long after the redraw that triggered them.
   */
  private trackComposer(): void {
    const composer = document.querySelector('.App-composer, #composer') as HTMLElement | null;
    if (!composer) return;

    const measure = () => {
      const style = getComputedStyle(composer);
      const height = style.display === 'none' || style.visibility === 'hidden' ? 0 : Math.round(composer.getBoundingClientRect().height);

      const root = document.documentElement;
      root.style.setProperty('--chirp-composer-h', `${height}px`);
      root.classList.toggle('chirp-composer-full', height > window.innerHeight * 0.4);
    };

    measure();

    if (!this.composerWatch && 'ResizeObserver' in window) {
      this.composerWatch = new ResizeObserver(measure);
      this.composerWatch.observe(composer);
    }
  }

  view(): Mithril.Children {
    const t = (k: string, data?: any) => app.translator.trans('linkrobins-chirp.forum.' + k, data);
    const { discussion, state } = this.attrs;
    const id = Number(discussion.id());
    const joined = state.inDiscussion(id);
    const speakers = state.speakers();
    const listeners = state.listenerCount();

    return m('.ChirpBar', { className: state.anyoneSpeaking ? 'ChirpBar--active' : '' }, [
      // ── Live badge + waveform ───────────────────────────────────────────
      m('.ChirpBar-live', [
        m('span.ChirpBadge', t('live_badge')),
        m(
          '.ChirpWave',
          { 'aria-hidden': 'true' },
          Array.from({ length: WAVE_BARS }, (_, i) => m('span.ChirpWave-bar', { style: { animationDelay: `${(i % 7) * 0.09}s` } }))
        ),
      ]),

      // ── The stage ───────────────────────────────────────────────────────
      speakers.length
        ? m(
            '.ChirpBar-stage',
            speakers.map((s) =>
              m(
                Tooltip,
                { text: s.muted ? t('speaker_muted', { name: s.name }) : s.name },
                m(
                  'span.ChirpSpeaker',
                  {
                    className: [s.speaking ? 'is-speaking' : '', s.muted ? 'is-muted' : ''].join(' ').trim(),
                    style: { background: s.color },
                  },
                  s.initial
                )
              )
            )
          )
        : m('span.ChirpBar-waiting', t('waiting_for_speakers')),

      // ── Audience: the number alone; the icon says what it counts, and the
      //    full sentence lives in the tooltip. ─────────────────────────────
      m(
        Tooltip,
        { text: t('listeners', { count: listeners }) },
        m('span.ChirpBar-count', [m('i.icon.fas.fa-headphones', { 'aria-hidden': 'true' }), m('span', String(listeners))])
      ),

      // ── Controls ────────────────────────────────────────────────────────
      m('.ChirpBar-actions', this.controls(id, joined)),
    ]);
  }

  private controls(id: number, joined: boolean): Mithril.Children[] {
    const t = (k: string, data?: any) => app.translator.trans('linkrobins-chirp.forum.' + k, data);
    const { discussion, state } = this.attrs;
    const actions: Mithril.Children[] = [];

    if (!joined) {
      actions.push(
        m(
          Button,
          {
            className: 'Button Button--size-sm ChirpBar-btn',
            icon: 'fas fa-headphones',
            loading: state.connecting,
            onclick: () => state.join(id, false),
          },
          t('join_listen')
        )
      );
    } else {
      if (state.canPublish) {
        actions.push(
          m(
            Button,
            {
              className: `Button Button--size-sm ChirpBar-btn ${state.muted ? 'is-muted' : ''}`,
              icon: state.muted ? 'fas fa-microphone-slash' : 'fas fa-microphone',
              onclick: () => state.setMuted(!state.muted),
            },
            state.muted ? t('unmute') : t('mute')
          )
        );
      } else if (discussion.attribute('canChirpSpeak')) {
        actions.push(
          m(
            Button,
            {
              className: 'Button Button--size-sm ChirpBar-btn',
              icon: 'fas fa-microphone',
              loading: state.connecting,
              onclick: () => state.join(id, true),
            },
            t('take_mic')
          )
        );
      }

      actions.push(m(Button, { className: 'Button Button--size-sm Button--flat', onclick: () => state.leave() }, t('leave')));
    }

    if (discussion.attribute('canChirpStart')) {
      actions.push(
        m(
          Button,
          {
            className: 'Button Button--size-sm ChirpBar-btn ChirpBar-end',
            icon: 'fas fa-circle-stop',
            onclick: () => {
              if (!confirm(String(t('confirm_end')))) return;
              app.request({ method: 'DELETE', url: `${app.forum.attribute('apiUrl')}/chirp/rooms/${id}` }).then(() => {
                state.leave();
                discussion.pushAttributes({ chirpIsLive: false });
                app.forum.pushAttributes({ chirpLiveDiscussionId: 0 });
                m.redraw();
              });
            },
          },
          t('end_room')
        )
      );
    }

    return actions;
  }
}
