import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import m from 'mithril';

interface Rec {
  id: number;
  duration: number;
  recordedAt: string | null;
}

/**
 * Recording strips under the FIRST post: opening the discussion is opening
 * the show, so the audio artifact sits at the top — never buried at the
 * bottom of the thread as post N of N. One strip per recording, oldest
 * first (a discussion can host rooms repeatedly). Dates render via plain
 * toLocaleDateString — helper functions like humanTime are NOT reliably
 * callable through the registry externals (the icon-helper landmine).
 */
export default class ChirpRecordingStrip extends Component<{ recordings: Rec[] }> {
  view() {
    return m(
      '.ChirpRecordingStrips',
      this.attrs.recordings.map((rec) => {
        const total = Math.max(0, Number(rec.duration || 0));
        const h = Math.floor(total / 3600);
        const min = Math.floor((total % 3600) / 60);
        const sec = total % 60;
        const two = (n: number) => String(n).padStart(2, '0');
        const dur = h > 0 ? `${h}:${two(min)}:${two(sec)}` : `${min}:${two(sec)}`;
        const src = `${app.forum.attribute('apiUrl')}/chirp/recordings/${rec.id}/audio`;

        return m('.ChirpRecordingStrip', { key: String(rec.id) }, [
          m('.ChirpRecordingStrip-head', [
            m('i.icon.fas.fa-microphone', { 'aria-hidden': 'true' }),
            m('span.ChirpRecordingStrip-label', app.translator.trans('linkrobins-chirp.forum.recording_label', { duration: dur })),
            rec.recordedAt ? m('span.ChirpRecordingStrip-time', new Date(rec.recordedAt).toLocaleDateString()) : null,
          ]),
          m('audio', { controls: true, preload: 'metadata', src }),
        ]);
      })
    );
  }
}
