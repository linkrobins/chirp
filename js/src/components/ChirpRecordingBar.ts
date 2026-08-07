import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import m from 'mithril';

interface Rec {
  id: number;
  duration: number;
  recordedAt: string | null;
}

const WAVE_BARS = 14;

/**
 * The room's afterlife: same ChirpBar, same spot above the post stream, but
 * the live controls give way to the recording. RECORDED badge, the idle
 * waveform silhouette (visual continuity with the live bar), duration/date,
 * and the player where the actions were. Static in flow — nothing to reach
 * for, so no sticky/dock behaviour. One bar per recording, oldest first.
 * Hidden while a room is actually live (the live bar owns the spot).
 */
export default class ChirpRecordingBar extends Component<{ recordings: Rec[] }> {
  view() {
    const t = (k: string, params?: any) => app.translator.trans('linkrobins-chirp.forum.' + k, params);

    return m(
      '.ChirpRecordingBars',
      this.attrs.recordings.map((rec) => {
        const total = Math.max(0, Number(rec.duration || 0));
        const h = Math.floor(total / 3600);
        const min = Math.floor((total % 3600) / 60);
        const sec = total % 60;
        const two = (n: number) => String(n).padStart(2, '0');
        const dur = h > 0 ? `${h}:${two(min)}:${two(sec)}` : `${min}:${two(sec)}`;
        const src = `${app.forum.attribute('apiUrl')}/chirp/recordings/${rec.id}/audio`;

        return m('.ChirpBar.ChirpBar--inline.ChirpBar--recording', { key: String(rec.id) }, [
          m('.ChirpBar-live', [
            m('span.ChirpBadge.ChirpBadge--rec.ChirpBadge--still', t('recorded_badge')),
            m(
              '.ChirpWave',
              { 'aria-hidden': 'true' },
              Array.from({ length: WAVE_BARS }, () => m('span.ChirpWave-bar'))
            ),
          ]),
          m('span.ChirpBar-count.ChirpRecordingBar-meta', [
            dur,
            rec.recordedAt ? ' · ' + new Date(rec.recordedAt).toLocaleDateString() : '',
          ]),
          m('audio.ChirpRecordingBar-player', { controls: true, preload: 'metadata', src }),
        ]);
      })
    );
  }
}
