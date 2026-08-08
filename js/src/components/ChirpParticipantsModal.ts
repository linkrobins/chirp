import app from 'flarum/forum/app';
import Modal, { type IInternalModalAttrs } from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import m from 'mithril';
import type ChirpState from '../ChirpState';
import type { Speaker } from '../ChirpState';

interface ChirpParticipantsModalAttrs extends IInternalModalAttrs {
  discussion: any;
  chirp: ChirpState;
}

/**
 * The full room roster behind the bar's ⋯ button: stage and audience as
 * sections, live (every LiveKit event redraws it). This is also where the
 * host moderates — Remove from stage for speakers, Remove from room for
 * anyone — which is why the bar only needs to show who's talking.
 */
export default class ChirpParticipantsModal extends Modal<ChirpParticipantsModalAttrs> {
  className(): string {
    return 'ChirpParticipantsModal Modal--small';
  }

  title(): Mithril.Children {
    return app.translator.trans('linkrobins-chirp.forum.participants');
  }

  content(): Mithril.Children {
    const t = (k: string, data?: any) => app.translator.trans('linkrobins-chirp.forum.' + k, data);
    const { discussion, chirp } = this.attrs;
    const id = Number(discussion.id());

    // Left (or got kicked) while the modal was open — nothing to list.
    if (!chirp.inDiscussion(id)) {
      return m('.Modal-body', m('p.ChirpRoster-empty', t('roster_gone')));
    }

    const roster = chirp.roster();
    const speakers = roster.filter((p) => p.onStage);
    const listeners = roster.filter((p) => !p.onStage);
    const isHostView =
      !!discussion.attribute('canChirpStart') || Number(app.session.user?.id() || 0) === Number(discussion.attribute('chirpRoomHostId') || 0);
    // Voice channels: joining is speaking, so moderation is mute/kick — no
    // stage to remove anyone from.
    const voice = discussion.attribute('chirpRoomMode') === 'persistent';

    const row = (p: Speaker) =>
      m(
        '.ChirpRoster-row',
        { key: p.key },
        [
          m(
            'span.ChirpSpeaker',
            { className: [p.speaking ? 'is-speaking' : '', p.onStage && p.muted ? 'is-muted' : ''].join(' ').trim(), style: { background: p.color } },
            p.initial
          ),
          m('span.ChirpRoster-name', [p.name, p.isLocal ? m('span.ChirpRoster-you', t('you')) : null]),
          p.onStage && p.muted && !p.isLocal ? m('i.icon.fas.fa-microphone-slash.ChirpRoster-muted', { 'aria-hidden': 'true' }) : null,
          isHostView && !p.isLocal && /^u\d+$/.test(p.key)
            ? m(
                'span.ChirpRoster-actions',
                [
                  p.onStage
                    ? m(Button, {
                        className: 'Button Button--icon Button--flat',
                        icon: 'fas fa-microphone-slash',
                        'aria-label': t(voice ? 'mute' : 'unstage'),
                        title: String(t(voice ? 'mute' : 'unstage')),
                        onclick: () => chirp.moderate(id, p.key, voice ? 'mute' : 'unstage'),
                      })
                    : null,
                  m(Button, {
                    className: 'Button Button--icon Button--flat ChirpRoster-kick',
                    icon: 'fas fa-user-slash',
                    'aria-label': t('kick'),
                    title: String(t('kick')),
                    onclick: () => {
                      if (confirm(String(t('confirm_kick')))) chirp.moderate(id, p.key, 'kick');
                    },
                  }),
                ].filter(Boolean)
              )
            : null,
        ].filter(Boolean)
      );

    // Voice channels: joining is speaking, so a stage/listening split is
    // meaningless — one flat list of everyone in the room.
    if (voice) {
      return m('.Modal-body.ChirpRoster', [
        roster.length ? m('.ChirpRoster-list', { key: 'l-all' }, roster.map(row)) : m('p.ChirpRoster-empty', { key: 'e-all' }, t('stage_empty')),
      ]);
    }

    // Pending raised hands — the host's queue, approve/decline per row.
    const hands = isHostView ? chirp.hands : [];

    return m(
      '.Modal-body.ChirpRoster',
      [
        hands.length ? m('h4.ChirpRoster-heading', { key: 'h-hands' }, t('hands_heading', { count: hands.length })) : null,
        hands.length
          ? m(
              '.ChirpRoster-list',
              { key: 'l-hands' },
              hands.map((h) =>
                m('.ChirpRoster-row', { key: 'hand-' + h.userId }, [
                  m('span.ChirpRoster-hand', '✋'),
                  m('span.ChirpRoster-name', h.name),
                  m('span.ChirpRoster-actions', [
                    m(Button, {
                      className: 'Button Button--icon Button--flat ChirpRoster-approve',
                      icon: 'fas fa-check',
                      'aria-label': t('approve'),
                      title: String(t('approve')),
                      onclick: () => chirp.resolveHand(id, h.userId, true),
                    }),
                    m(Button, {
                      className: 'Button Button--icon Button--flat ChirpRoster-kick',
                      icon: 'fas fa-xmark',
                      'aria-label': t('decline'),
                      title: String(t('decline')),
                      onclick: () => chirp.resolveHand(id, h.userId, false),
                    }),
                  ]),
                ])
              )
            )
          : null,
        m('h4.ChirpRoster-heading', { key: 'h-stage' }, t('on_stage', { count: speakers.length })),
        speakers.length
          ? m('.ChirpRoster-list', { key: 'l-stage' }, speakers.map(row))
          : m('p.ChirpRoster-empty', { key: 'e-stage' }, t('stage_empty')),
        listeners.length ? m('h4.ChirpRoster-heading', { key: 'h-aud' }, t('listening_heading', { count: listeners.length })) : null,
        listeners.length ? m('.ChirpRoster-list', { key: 'l-aud' }, listeners.map(row)) : null,
      ].filter(Boolean)
    );
  }
}
