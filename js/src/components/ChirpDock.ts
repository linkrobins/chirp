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
  view(): Mithril.Children {
    const t = (k: string, d?: any) => app.translator.trans('linkrobins-chirp.forum.' + k, d);
    const { state } = this.attrs;

    if (!state.connected()) return null;

    // Stand down wherever the room's own toolbar is already on screen — its
    // discussion page, or its row in the discussion list.
    if (document.querySelector(`.ChirpBar[data-chirp-room="${state.discussionId}"]`)) return null;

    const speakers = state.speakers();

    return m('.ChirpDock', { className: state.anyoneSpeaking ? 'is-active' : '' }, [
      m('.ChirpDock-main', [
        m('span.ChirpBadge', t('live_badge')),
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
