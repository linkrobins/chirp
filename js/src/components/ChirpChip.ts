import app from 'flarum/forum/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Tooltip from 'flarum/common/components/Tooltip';
import type Mithril from 'mithril';
import m from 'mithril';
import type ChirpState from '../ChirpState';

interface ChirpChipAttrs extends ComponentAttrs {
  discussion: any;
  state: ChirpState;
}

/**
 * The live marker on the discussion list. Not just a badge — it's the join
 * control: a small waveform that says "there is audio here right now", and a
 * click that drops you straight into the room without leaving the index.
 */
export default class ChirpChip extends Component<ChirpChipAttrs> {
  view(): Mithril.Children {
    const t = (k: string) => app.translator.trans('linkrobins-chirp.forum.' + k);
    const { discussion, state } = this.attrs;
    const id = Number(discussion.id());
    const here = state.inDiscussion(id);

    return m(
      Tooltip,
      { text: here ? t('leave') : t('join_listen') },
      m(
        'button.ChirpChip',
        {
          type: 'button',
          className: [here ? 'is-listening' : '', state.connecting ? 'is-busy' : ''].join(' ').trim(),
          'aria-label': String(here ? t('leave') : t('join_listen')),
          onclick: (e: Event) => {
            // The chip lives inside the discussion link — don't navigate.
            e.preventDefault();
            e.stopPropagation();
            if (here) {
              state.leave();
              return;
            }
            state.describe(String(discussion.title()), app.route.discussion(discussion));
            state.join(id, false);
          },
        },
        [
          m('span.ChirpChip-label', t('live_badge')),
          m(
            '.ChirpChip-wave',
            { 'aria-hidden': 'true' },
            Array.from({ length: 5 }, (_, i) => m('span', { style: { animationDelay: `${i * 0.12}s` } }))
          ),
          m('i.ChirpChip-icon.fas', {
            className: here ? 'fa-circle-stop' : 'fa-headphones',
            'aria-hidden': 'true',
          }),
        ]
      )
    );
  }
}
