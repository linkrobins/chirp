import app from 'flarum/forum/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import m from 'mithril';
import type ChirpState from '../ChirpState';

interface ChirpBarAttrs extends ComponentAttrs {
  discussion: any;
  state: ChirpState;
}

/**
 * The live-room bar shown in the discussion hero while a room is live: LIVE
 * badge, listener count, and the join/leave/mic controls appropriate to the
 * viewer. The thread below IS the chat — this bar deliberately stays one line.
 */
export default class ChirpBar extends Component<ChirpBarAttrs> {
  view(): Mithril.Children {
    const t = (k: string, data?: any) => app.translator.trans('linkrobins-chirp.forum.' + k, data);
    const { discussion, state } = this.attrs;
    const id = Number(discussion.id());
    const joined = state.inDiscussion(id);

    const actions: Mithril.Children[] = [];

    if (!joined) {
      actions.push(
        m(
          Button,
          {
            className: 'Button Button--primary Button--size-sm',
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
              className: 'Button Button--size-sm',
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
              className: 'Button Button--size-sm',
              icon: 'fas fa-microphone',
              loading: state.connecting,
              onclick: () => state.join(id, true),
            },
            t('take_mic')
          )
        );
      }

      actions.push(
        m(
          Button,
          {
            className: 'Button Button--size-sm',
            onclick: () => state.leave(),
          },
          t('leave')
        )
      );
    }

    if (discussion.attribute('canChirpStart')) {
      actions.push(
        m(
          Button,
          {
            className: 'Button Button--size-sm Button--danger',
            onclick: () => {
              if (!confirm(String(t('confirm_end')))) return;
              app.request({ method: 'DELETE', url: `${app.forum.attribute('apiUrl')}/chirp/rooms/${id}` }).then(() => {
                state.leave();
                discussion.pushAttributes({ chirpIsLive: false });
                m.redraw();
              });
            },
          },
          t('end_room')
        )
      );
    }

    return m('.ChirpBar', [
      m('span.ChirpBar-status', [m('span.ChirpBadge', t('live_badge')), t('live_banner')]),
      joined && state.participantCount > 0 ? m('span.ChirpBar-count', t('listeners', { count: state.participantCount })) : null,
      m('.ChirpBar-actions', actions),
    ]);
  }
}
