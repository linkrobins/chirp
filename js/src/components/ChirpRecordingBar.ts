import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import m from 'mithril';
import type Mithril from 'mithril';

import ComposerTracker from '../composerTracker';

interface Rec {
  id: number;
  duration: number;
  recordedAt: string | null;
}

const WAVE_BARS = 14;

/**
 * The room's afterlife: the SAME persistent bar the live session had —
 * sticky on desktop, docked to the bottom on phones, riding above the
 * composer — but the live controls give way to the recording. RECORDED
 * badge (pulse stilled), the idle waveform silhouette for continuity,
 * duration/date, and the player where the actions were. One bar; when a
 * discussion has hosted several rooms, a small picker switches between
 * recordings (stacked docks can't work). Hidden while a room is actually
 * live — the live bar owns the spot.
 */
export default class ChirpRecordingBar extends Component<{ recordings: Rec[] }> {
  private selected = -1; // -1 = latest
  private tracker = new ComposerTracker('chirp-recorded');

  oncreate(vnode: Mithril.VnodeDOM<{ recordings: Rec[] }>) {
    super.oncreate(vnode);
    this.tracker.start();
  }

  onupdate(vnode: Mithril.VnodeDOM<{ recordings: Rec[] }>) {
    super.onupdate(vnode);
    this.tracker.update();
  }

  onremove(vnode: Mithril.VnodeDOM<{ recordings: Rec[] }>) {
    super.onremove(vnode);
    this.tracker.stop();
  }

  view() {
    const t = (k: string, params?: any) => app.translator.trans('linkrobins-chirp.forum.' + k, params);
    const recordings = this.attrs.recordings;
    const index = this.selected >= 0 && this.selected < recordings.length ? this.selected : recordings.length - 1;
    const rec = recordings[index];
    if (!rec) return null;

    const total = Math.max(0, Number(rec.duration || 0));
    const h = Math.floor(total / 3600);
    const min = Math.floor((total % 3600) / 60);
    const sec = total % 60;
    const two = (n: number) => String(n).padStart(2, '0');
    const dur = h > 0 ? `${h}:${two(min)}:${two(sec)}` : `${min}:${two(sec)}`;
    const src = `${app.forum.attribute('apiUrl')}/chirp/recordings/${rec.id}/audio`;

    // ⚠️ Mithril: a fragment must be ALL-keyed or ALL-unkeyed — one keyed
    // child among unkeyed siblings throws and takes the whole DiscussionPage
    // down. Every child is keyed (nulls excluded via filter), and the audio's
    // key is the recording id so switching SWAPS the element — a playing
    // <audio> ignores src changes.
    return m(
      '.ChirpBar.ChirpBar--recording',
      [
        m('.ChirpBar-live', { key: 'live' }, [
          m('span.ChirpBadge.ChirpBadge--rec.ChirpBadge--still', t('recorded_badge')),
          m(
            '.ChirpWave',
            { 'aria-hidden': 'true' },
            Array.from({ length: WAVE_BARS }, () => m('span.ChirpWave-bar'))
          ),
        ]),
        recordings.length > 1
          ? m(
              'select.FormControl.ChirpRecordingBar-pick',
              {
                key: 'pick',
                value: String(index),
                onchange: (e: Event) => {
                  this.selected = Number((e.target as HTMLSelectElement).value);
                },
              },
              recordings.map((r, i) =>
                m('option', { value: String(i) }, r.recordedAt ? new Date(r.recordedAt).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' }) : `#${r.id}`)
              )
            )
          : null,
        m('span.ChirpBar-count.ChirpRecordingBar-meta', { key: 'meta' }, [dur, rec.recordedAt ? ' · ' + new Date(rec.recordedAt).toLocaleDateString() : '']),
        m('audio.ChirpRecordingBar-player', { key: 'rec-' + rec.id, controls: true, preload: 'metadata', src }),
      ].filter(Boolean)
    );
  }
}
