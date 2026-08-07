import app from 'flarum/forum/app';
import EventPost from 'flarum/forum/components/EventPost';
import m from 'mithril';

/**
 * The recording event post: the standard event-post line (icon + sentence)
 * with the audio player rendered inside the description block. The <audio>
 * src is the visibility-checked streaming endpoint, so the player works for
 * exactly the people who can read the thread. NB: the `icon` helper isn't
 * callable through the registry external (landmine #1) — EventPost.icon()
 * only returns a class NAME, which core renders itself, so it's safe here.
 */
export default class ChirpRecordingPost extends EventPost {
  icon() {
    return 'fas fa-microphone';
  }

  descriptionKey() {
    return 'linkrobins-chirp.forum.recording_post';
  }

  descriptionData() {
    const content = (this.attrs.post as any).content() || {};
    const total = Math.max(0, Number(content.duration || 0));
    const h = Math.floor(total / 3600);
    const min = Math.floor((total % 3600) / 60);
    const sec = total % 60;
    const two = (n: number) => String(n).padStart(2, '0');
    return { duration: h > 0 ? `${h}:${two(min)}:${two(sec)}` : `${min}:${two(sec)}` };
  }

  description(data: Record<string, unknown>) {
    const content = (this.attrs.post as any).content() || {};
    const src = `${app.forum.attribute('apiUrl')}/chirp/recordings/${content.recordingId}/audio`;

    return [
      m('span', app.translator.trans(this.descriptionKey(), data)),
      m(
        '.ChirpRecordingPost-player',
        m('audio', { controls: true, preload: 'metadata', src })
      ),
    ];
  }
}
