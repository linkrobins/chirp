import app from 'flarum/forum/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import type Mithril from 'mithril';
import m from 'mithril';
import formatCountdown from '../utils/formatCountdown';

interface ChirpScheduledChipAttrs extends ComponentAttrs {
  discussion: any;
}

/**
 * The discovery cue on discussion-list rows: a small "LIVE in 2h" badge on
 * the info line for a scheduled show. Render-only — the row's link is the
 * interaction, and the discussion page (countdown bar) is the source of
 * truth; a cached list payload may lag behind a fresh schedule, which is
 * acceptable for a discovery chip.
 *
 * Same coarse tick as ChirpScheduleBar: minute precision, 15s redraw. The
 * redraw alone can't repaint a list row — SubtreeRetainer freezes rows — so
 * forum.ts registers the minute bucket in the row's subtree checks; this
 * timer just makes sure a redraw happens for the retainer to notice.
 */
export default class ChirpScheduledChip extends Component<ChirpScheduledChipAttrs> {
  private timer: ReturnType<typeof setInterval> | null = null;

  oncreate(vnode: Mithril.VnodeDOM<ChirpScheduledChipAttrs>) {
    super.oncreate(vnode);
    this.timer = setInterval(() => m.redraw(), 15000);
  }

  onremove(vnode: Mithril.VnodeDOM<ChirpScheduledChipAttrs>) {
    super.onremove(vnode);
    if (this.timer) clearInterval(this.timer);
  }

  view(): Mithril.Children {
    const startsAt = new Date(String(this.attrs.discussion?.attribute?.('chirpScheduledAt')));
    if (isNaN(startsAt.getTime())) return null;

    const countdown = formatCountdown(startsAt.getTime() - Date.now());

    return m(
      'span.ChirpBadge.ChirpBadge--schedule.ChirpScheduledChip',
      countdown
        ? app.translator.trans('linkrobins-chirp.forum.live_in', { time: countdown })
        : app.translator.trans('linkrobins-chirp.forum.starting_soon')
    );
  }
}
