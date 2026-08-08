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
  // Split date + time: datetime-local's time half is a fiddly type-in field,
  // while separate inputs each get a real native picker. Prefilled with
  // tomorrow 20:00 so accepting the suggestion is one tap.
  private date = (() => {
    const d = new Date(Date.now() + 86400e3);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  })();
  private time = '20:00';
  private saving = false;

  className(): string {
    return 'ChirpScheduleModal Modal--small';
  }

  title(): Mithril.Children {
    return app.translator.trans('linkrobins-chirp.forum.schedule_title');
  }

  content(): Mithril.Children {
    const t = (k: string, data?: any) => app.translator.trans('linkrobins-chirp.forum.' + k, data);
    const today = new Date();
    const minDate = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
    // Say the timezone out loud — "8pm" is ambiguous, "8pm (America/Chicago,
    // UTC-5)" is a plan. Stored as UTC; every viewer sees their own local time.
    const zone = (() => {
      try {
        const tz = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
        const off = -new Date().getTimezoneOffset();
        const hh = Math.trunc(Math.abs(off) / 60);
        const mm = Math.abs(off) % 60;
        return `${tz} (UTC${off < 0 ? '-' : '+'}${hh}${mm ? ':' + String(mm).padStart(2, '0') : ''})`;
      } catch {
        return 'UTC';
      }
    })();

    return m('.Modal-body', [
      m('.Form-group', [
        m('label', t('schedule_label')),
        m('.ChirpSchedule-inputs', [
          m('input.FormControl', {
            type: 'date',
            min: minDate,
            value: this.date,
            oninput: (e: Event) => (this.date = (e.target as HTMLInputElement).value),
          }),
          m('input.FormControl', {
            type: 'time',
            value: this.time,
            oninput: (e: Event) => (this.time = (e.target as HTMLInputElement).value),
          }),
        ]),
        m('.helpText', t('schedule_tz', { zone })),
        m('.helpText', t('schedule_help')),
      ]),
      m('.Form-group', [
        m(
          Button,
          { className: 'Button Button--primary Button--block', loading: this.saving, disabled: !this.date || !this.time, onclick: () => this.submit() },
          t('schedule_submit')
        ),
      ]),
    ]);
  }

  private async submit(): Promise<void> {
    const when = new Date(`${this.date}T${this.time}`);
    if (!this.date || !this.time || isNaN(when.getTime()) || when.getTime() <= Date.now()) return;

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
