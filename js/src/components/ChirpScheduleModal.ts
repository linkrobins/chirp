import app from 'flarum/forum/app';
import Modal, { type IInternalModalAttrs } from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import m from 'mithril';

interface ChirpScheduleModalAttrs extends IInternalModalAttrs {
  discussion: any;
}

/**
 * "Going live Friday 8pm" — pick a local date & time, followers get the
 * heads-up, the discussion carries a countdown until the host actually goes
 * live (which consumes the schedule).
 */
export default class ChirpScheduleModal extends Modal<ChirpScheduleModalAttrs> {
  private value = '';
  private saving = false;

  className(): string {
    return 'ChirpScheduleModal Modal--small';
  }

  title(): Mithril.Children {
    return app.translator.trans('linkrobins-chirp.forum.schedule_title');
  }

  content(): Mithril.Children {
    const t = (k: string) => app.translator.trans('linkrobins-chirp.forum.' + k);
    // datetime-local wants a local-time string; default to one hour out.
    const min = new Date(Date.now() + 5 * 60000);
    const fmt = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}T${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;

    return m('.Modal-body', [
      m('.Form-group', [
        m('label', t('schedule_label')),
        m('input.FormControl', {
          type: 'datetime-local',
          min: fmt(min),
          value: this.value,
          oninput: (e: Event) => (this.value = (e.target as HTMLInputElement).value),
        }),
        m('.helpText', t('schedule_help')),
      ]),
      m('.Form-group', [
        m(
          Button,
          { className: 'Button Button--primary Button--block', loading: this.saving, disabled: !this.value, onclick: () => this.submit() },
          t('schedule_submit')
        ),
      ]),
    ]);
  }

  private async submit(): Promise<void> {
    const when = new Date(this.value);
    if (!this.value || isNaN(when.getTime()) || when.getTime() <= Date.now()) return;

    this.saving = true;
    try {
      const res = await app.request<any>({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/chirp/rooms/${this.attrs.discussion.id()}/schedule`,
        body: { startsAt: when.toISOString() },
      });
      this.attrs.discussion.pushAttributes({ chirpScheduledAt: res?.startsAt || when.toISOString() });
      this.hide();
    } finally {
      this.saving = false;
      m.redraw();
    }
  }
}
