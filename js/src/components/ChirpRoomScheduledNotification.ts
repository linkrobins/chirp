import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';

/**
 * "X scheduled a live room" — the heads-up followers can plan around. The
 * scheduled time renders in the excerpt line, localized.
 */
export default class ChirpRoomScheduledNotification extends Notification {
  icon(): string {
    return 'fas fa-calendar-days';
  }

  href(): string {
    return app.route.discussion(this.attrs.notification.subject() as any);
  }

  content() {
    const user = this.attrs.notification.fromUser();
    return app.translator.trans('linkrobins-chirp.forum.notifications.scheduled_text', { user });
  }

  excerpt() {
    const startsAt = new Date(String((this.attrs.notification.content() as any)?.startsAt || ''));
    if (isNaN(startsAt.getTime())) return null;
    return startsAt.toLocaleString(undefined, { weekday: 'long', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
  }
}
