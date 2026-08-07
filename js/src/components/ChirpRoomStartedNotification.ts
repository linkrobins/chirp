import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';

/**
 * "X went live in <discussion>" (or opened a voice channel). Clicking lands
 * on the discussion, where the bar has the join button.
 */
export default class ChirpRoomStartedNotification extends Notification {
  icon() {
    return 'fas fa-microphone';
  }

  href() {
    const discussion = (this.attrs.notification as any).subject();

    return discussion ? app.route.discussion(discussion) : '#';
  }

  content() {
    const notification = this.attrs.notification as any;
    const user = notification.fromUser();
    const mode = (notification.content() || {}).mode;

    return app.translator.trans(
      mode === 'persistent' ? 'linkrobins-chirp.forum.notifications.voice_opened_text' : 'linkrobins-chirp.forum.notifications.went_live_text',
      { user }
    );
  }
}
