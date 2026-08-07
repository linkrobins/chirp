import app from 'flarum/forum/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Tooltip from 'flarum/common/components/Tooltip';
import type Mithril from 'mithril';
import m from 'mithril';
import type ChirpState from '../ChirpState';
import ChirpParticipantsModal from './ChirpParticipantsModal';
import ComposerTracker from '../composerTracker';

interface ChirpBarAttrs extends ComponentAttrs {
  discussion: any;
  state: ChirpState;
  /** Rendered inside a discussion-list row: no sticky/fixed docking. */
  inline?: boolean;
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
  // while one is mounted (see forum.less). The composer plumbing is shared
  // with the recording bar — see composerTracker.ts.
  private tracker = new ComposerTracker('chirp-live');

  oncreate(vnode: Mithril.VnodeDOM<ChirpBarAttrs>) {
    super.oncreate(vnode);
    this.tracker.start();

    // Host in hand mode: hydrate hands raised before this bar mounted (the
    // data channel only covers hands raised while we're watching).
    const { discussion, state } = this.attrs;
    const policy = state.speakPolicy || (discussion.attribute('chirpSpeakPolicy') as string) || 'open';
    if (policy === 'hand' && discussion.attribute('canChirpStart')) {
      void state.loadHands(Number(discussion.id()));
    }
  }

  onupdate(vnode: Mithril.VnodeDOM<ChirpBarAttrs>) {
    super.onupdate(vnode);
    this.tracker.update();
  }

  onremove(vnode: Mithril.VnodeDOM<ChirpBarAttrs>) {
    super.onremove(vnode);
    this.tracker.stop();
  }

  view(): Mithril.Children {
    const t = (k: string, data?: any) => app.translator.trans('linkrobins-chirp.forum.' + k, data);
    const { discussion, state } = this.attrs;
    const id = Number(discussion.id());
    const joined = state.inDiscussion(id);
    const speakers = state.speakers();
    const featured = state.featuredSpeaker();
    const mode = (discussion.attribute('chirpRoomMode') as string) || 'live';
    const listeners = state.listenerCount();

    return m(
      '.ChirpBar',
      {
        className: [state.anyoneSpeaking ? 'ChirpBar--active' : '', this.attrs.inline ? 'ChirpBar--inline' : ''].join(' ').trim(),
        // Lets the floating dock know this room already has controls on screen.
        'data-chirp-room': String(id),
        // In a list row, the controls must not trigger the row's navigation.
        onclick: this.attrs.inline ? (e: Event) => e.stopPropagation() : undefined,
      },
      [
        // ── Live badge + waveform ───────────────────────────────────────────
        m('.ChirpBar-live', [
          mode === 'persistent'
            ? m('span.ChirpBadge.ChirpBadge--voice', t('voice_badge'))
            : m('span.ChirpBadge', t('live_badge')),
          state.recording ? m('span.ChirpBadge.ChirpBadge--rec', { title: t('recording_title') }, t('recording_badge')) : null,
          m(
            '.ChirpWave',
            { 'aria-hidden': 'true' },
            Array.from({ length: WAVE_BARS }, (_, i) => m('span.ChirpWave-bar', { style: { animationDelay: `${(i % 7) * 0.09}s` } }))
          ),
        ]),

        // ── The stage: only whoever is talking (a big stage would flood the
        //    strip — the ⋯ modal holds the full roster + host moderation). ──
        featured
          ? m('.ChirpBar-stage', [
              m(
                Tooltip,
                { text: featured.muted ? t('speaker_muted', { name: featured.name }) : featured.name },
                m(
                  'span.ChirpSpeaker',
                  {
                    className: [featured.speaking ? 'is-speaking' : '', featured.muted ? 'is-muted' : ''].join(' ').trim(),
                    style: { background: featured.color },
                    onclick: joined ? (e: Event) => { e.stopPropagation(); this.openRoster(); } : undefined,
                  },
                  featured.initial
                )
              ),
              speakers.length > 1 ? m('span.ChirpBar-morecount', `+${speakers.length - 1}`) : null,
              joined
                ? m(Button, {
                    className: 'Button Button--icon Button--flat ChirpBar-roster',
                    icon: 'fas fa-ellipsis',
                    'aria-label': t('participants'),
                    title: String(t('participants')),
                    onclick: (e: Event) => { e.stopPropagation(); this.openRoster(); },
                  })
                : null,
            ])
          : m('span.ChirpBar-waiting', t(mode === 'persistent' ? 'voice_empty' : 'waiting_for_speakers')),

        // ── Audience: the number alone; the icon says what it counts, and the
        //    full sentence lives in the tooltip. ─────────────────────────────
        m(
          Tooltip,
          { text: t('listeners', { count: listeners }) },
          m('span.ChirpBar-count', [m('i.icon.fas.fa-headphones', { 'aria-hidden': 'true' }), m('span', String(listeners))])
        ),

        // ── Controls ────────────────────────────────────────────────────────
        m('.ChirpBar-actions', this.controls(id, joined)),
      ]
    );
  }

  private openRoster(): void {
    app.modal.show(ChirpParticipantsModal, { discussion: this.attrs.discussion, chirp: this.attrs.state });
  }

  private controls(id: number, joined: boolean): Mithril.Children[] {
    const t = (k: string, data?: any) => app.translator.trans('linkrobins-chirp.forum.' + k, data);
    const { discussion, state } = this.attrs;
    const mode = (discussion.attribute('chirpRoomMode') as string) || 'live';
    const actions: Mithril.Children[] = [];

    if (!joined) {
      actions.push(
        m(
          Button,
          {
            className: 'Button Button--size-sm ChirpBar-btn',
            icon: 'fas fa-headphones',
            loading: state.connecting,
            onclick: () => {
              state.describe(String(discussion.title()), app.route.discussion(discussion));
              state.join(id, false);
            },
          },
          t(mode === 'persistent' ? 'join_channel' : 'join_listen')
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
      } else if (discussion.attribute('chirpSpeakEligible')) {
        const policy = state.speakPolicy || (discussion.attribute('chirpSpeakPolicy') as string) || 'open';
        const hostId = Number(discussion.attribute('chirpRoomHostId') || 0);
        const isHost = !!discussion.attribute('canChirpStart') || Number(app.session.user?.id() || 0) === hostId;

        if (policy === 'hand' && !isHost && state.handStatus !== 'approved') {
          // Raise-hand flow: the mic unlocks when the host approves.
          actions.push(
            m(
              Button,
              {
                className: 'Button Button--size-sm ChirpBar-btn',
                icon: 'fas fa-hand',
                disabled: state.handStatus === 'pending',
                title: state.handStatus === 'declined' ? String(t('hand_declined')) : undefined,
                onclick: () => state.raiseHand(id),
              },
              state.handStatus === 'pending' ? t('hand_pending') : t('raise_hand')
            )
          );
        } else {
          actions.push(
            m(
              Button,
              {
                className: 'Button Button--size-sm ChirpBar-btn',
                icon: 'fas fa-microphone',
                loading: state.connecting,
                onclick: () => {
                  state.describe(String(discussion.title()), app.route.discussion(discussion));
                  state.join(id, true);
                },
              },
              t('take_mic')
            )
          );
        }
      }

      actions.push(m(Button, { className: 'Button Button--size-sm Button--flat', onclick: () => state.leave() }, t('leave')));
    }

    if (discussion.attribute('canChirpStart')) {
      const policy = state.speakPolicy || (discussion.attribute('chirpSpeakPolicy') as string) || 'open';

      // Pending hands — approve brings them straight up on stage.
      if (policy === 'hand') {
        state.hands.forEach((h) => {
          actions.push(
            m('span.ChirpHand', { key: undefined }, [
              m('span.ChirpHand-name', '\u270B ' + h.name),
              m(Button, { className: 'Button Button--size-sm ChirpHand-yes', icon: 'fas fa-check', title: String(t('approve')), onclick: () => state.resolveHand(id, h.userId, true) }),
              m(Button, { className: 'Button Button--size-sm ChirpHand-no', icon: 'fas fa-xmark', title: String(t('decline')), onclick: () => state.resolveHand(id, h.userId, false) }),
            ])
          );
        });
      }

      // The live policy switcher — the bar-switcher model (admin sets the
      // default, the host opens up or locks down mid-show).
      actions.push(
        m(
          'span.ChirpRecordingBar-pickwrap',
          m(
            'select.ChirpRecordingBar-pick.ChirpBar-policy',
            { value: policy, onchange: (e: Event) => state.setPolicy(id, (e.target as HTMLSelectElement).value) },
            [
              m('option', { value: 'open' }, t('policy_open_short')),
              m('option', { value: 'hand' }, t('policy_hand_short')),
              m('option', { value: 'op' }, t('policy_op_short')),
            ]
          )
        )
      );

      actions.push(
        m(
          Button,
          {
            className: 'Button Button--size-sm ChirpBar-btn ChirpBar-end',
            icon: 'fas fa-circle-stop',
            onclick: () => {
              if (!confirm(String(t(mode === 'persistent' ? 'confirm_close_channel' : 'confirm_end')))) return;
              app.request({ method: 'DELETE', url: `${app.forum.attribute('apiUrl')}/chirp/rooms/${id}` }).then(() => {
                state.leave();
                discussion.pushAttributes({ chirpIsLive: false });
                app.forum.pushAttributes({ chirpLiveDiscussionId: 0 });
                m.redraw();
              });
            },
          },
          t(mode === 'persistent' ? 'close_channel' : 'end_room')
        )
      );
    }

    return actions;
  }
}
