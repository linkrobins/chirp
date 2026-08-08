import app from 'flarum/forum/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import m from 'mithril';
import ComposerTracker from '../composerTracker';

interface ChirpScheduleBarAttrs extends ComponentAttrs {
  discussion: any;
}

/**
 * The countdown strip: "LIVE in 2h 13m" in the exact spot the live bar will
 * take over when the host shows up. Follows you like the live bar does
 * (sticky desktop, docked phones, composer-aware); ticks on a coarse timer —
 * minute precision is the honest resolution for a show that starts when the
 * host clicks a button.
 */
export default class ChirpScheduleBar extends Component<ChirpScheduleBarAttrs> {
  private timer: ReturnType<typeof setInterval> | null = null;

  // Same persistence as the live and recording bars: sticky on desktop,
  // bottom-docked on phones, composer-aware — the countdown follows you.
  private tracker = new ComposerTracker('chirp-scheduled');

  oncreate(vnode: Mithril.VnodeDOM<ChirpScheduleBarAttrs>) {
    super.oncreate(vnode);
    this.tracker.start();
    this.timer = setInterval(() => m.redraw(), 15000);
  }

  onupdate(vnode: Mithril.VnodeDOM<ChirpScheduleBarAttrs>) {
    super.onupdate(vnode);
    this.tracker.update();
  }

  onremove(vnode: Mithril.VnodeDOM<ChirpScheduleBarAttrs>) {
    super.onremove(vnode);
    this.tracker.stop();
    if (this.timer) clearInterval(this.timer);
  }

  view(): Mithril.Children {
    const t = (k: string, data?: any) => app.translator.trans('linkrobins-chirp.forum.' + k, data);
    const { discussion } = this.attrs;
    const startsAt = new Date(String(discussion.attribute('chirpScheduledAt')));
    if (isNaN(startsAt.getTime())) return null;

    const ms = startsAt.getTime() - Date.now();
    const countdown = (() => {
      if (ms <= 60000) return null; // "any moment" territory
      const mins = Math.floor(ms / 60000);
      const d = Math.floor(mins / 1440);
      const h = Math.floor((mins % 1440) / 60);
      const min = mins % 60;
      if (d > 0) return `${d}d ${h}h`;
      if (h > 0) return `${h}h ${min}m`;
      return `${min}m`;
    })();

    const canCancel = !!discussion.attribute('canChirpStart');

    return m('.ChirpBar.ChirpBar--schedule', [
      m('.ChirpBar-live', [m('span.ChirpBadge.ChirpBadge--schedule', countdown ? t('live_in', { time: countdown }) : t('starting_soon'))]),
      m(
        'span.ChirpSchedule-when',
        startsAt.toLocaleString(undefined, { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
      ),
      m('.ChirpBar-actions', [
        canCancel
          ? m(
              Button,
              {
                className: 'Button Button--size-sm ChirpBar-btn',
                icon: 'fas fa-calendar-xmark',
                onclick: () => {
                  if (!confirm(String(t('confirm_cancel_schedule')))) return;
                  app.request({ method: 'DELETE', url: `${app.forum.attribute('apiUrl')}/chirp/rooms/${discussion.id()}/schedule` }).then(() => {
                    discussion.pushAttributes({ chirpScheduledAt: null });
                    m.redraw();
                  });
                },
              },
              t('schedule_cancel')
            )
          : null,
      ]),
    ]);
  }
}
