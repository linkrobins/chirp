import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import saveSettings from 'flarum/admin/utils/saveSettings';
import type Mithril from 'mithril';
import m from 'mithril';

interface KeyStatus {
  connected: boolean;
  handle: string;
}

/**
 * Multi-channel keys: one input per purchased channel. Each key connects one
 * channel — and each channel powers ONE standing voice channel plus one live
 * broadcast at a time, so "more voice channels" means "more keys here".
 *
 * Saves itself (saveSettings → the Saved event runs the config exchange
 * server-side) instead of riding the page's submit button, because the
 * exchange outcome (connected / bad key) should be visible immediately —
 * we refetch /chirp/channels for fresh per-key status after every save.
 */
export default class ChirpKeysPanel extends Component {
  private keys: string[] = [];
  private status: KeyStatus[] = [];
  private saving = false;
  private loaded = false;

  private apiUrl(): string {
    const forum = (app.data.resources || []).find((r: any) => r.type === 'forums') as any;
    return forum?.attributes?.apiUrl || '/api';
  }

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);

    // Stored keys; fall back to the v1.0 single-key setting so upgrades
    // show their existing key as row one.
    try {
      const raw = app.data.settings['linkrobins-chirp.channel-keys'];
      const parsed = raw ? JSON.parse(raw) : null;
      this.keys = Array.isArray(parsed) ? parsed.map(String) : [];
    } catch {
      this.keys = [];
    }
    if (!this.keys.length) {
      const legacy = (app.data.settings['linkrobins-chirp.channel-key'] || '').trim();
      this.keys = legacy ? [legacy] : [''];
    }

    void this.refreshStatus();
  }

  private async refreshStatus(): Promise<void> {
    try {
      const res = await app.request<any>({ url: `${this.apiUrl()}/chirp/channels` });
      this.status = res?.keys || [];
    } catch {
      this.status = [];
    }
    this.loaded = true;
    m.redraw();
  }

  private async save(): Promise<void> {
    if (this.saving) return;
    this.saving = true;

    const cleaned = this.keys.map((k) => k.trim()).filter(Boolean);

    try {
      await saveSettings({ 'linkrobins-chirp.channel-keys': JSON.stringify(cleaned) });
      // The exchange ran inside that save; pull fresh per-key status and
      // the aggregate flag the banner reads.
      await this.refreshStatus();
      app.data.settings['linkrobins-chirp.connected'] = this.status.some((s) => s.connected) ? '1' : '0';
      this.keys = cleaned.length ? cleaned : [''];
    } finally {
      this.saving = false;
      m.redraw();
    }
  }

  view(): Mithril.Children {
    const t = (k: string) => app.translator.trans('linkrobins-chirp.admin.' + k);

    return m('.Form-group.ChirpKeys', [
      m('label', t('keys_label')),
      m('.helpText', t('keys_help')),

      // NO vnode keys here: these rows share a fragment with the unkeyed
      // label/help/actions siblings, and mithril requires all-or-none keys
      // per fragment. Index-reuse is fine — the inputs are controlled.
      //
      // Each row is ONE composed field (Karl 2026-08-08: a status badge and
      // ✕ floating beside the input didn't read as a unit): a FormControl-
      // styled shell holds a borderless input + status pill + flat remove.
      ...this.keys.map((key, i) =>
        m('.ChirpKeys-row', [
          m('.ChirpKeys-field.FormControl', [
            m('input.ChirpKeys-input', {
              value: key,
              placeholder: String(t('keys_placeholder')),
              oninput: (e: Event) => {
                this.keys[i] = (e.target as HTMLInputElement).value;
              },
            }),
            // Status is positional: the exchange stores channels in key order.
            this.loaded && key.trim() && this.status[i]
              ? m(
                  'span.ChirpKeys-status',
                  { className: this.status[i].connected ? 'ChirpKeys-status--ok' : 'ChirpKeys-status--bad' },
                  this.status[i].connected ? t('keys_status_connected') : t('keys_status_bad')
                )
              : null,
            this.keys.length > 1 || key.trim()
              ? m(Button, {
                  className: 'Button Button--icon Button--flat ChirpKeys-remove',
                  icon: 'fas fa-times',
                  'aria-label': String(t('keys_remove')),
                  onclick: () => {
                    this.keys.splice(i, 1);
                    if (!this.keys.length) this.keys = [''];
                  },
                })
              : null,
          ]),
        ])
      ),

      m('.ChirpKeys-actions', [
        m(
          Button,
          {
            className: 'Button',
            icon: 'fas fa-plus',
            onclick: () => {
              this.keys.push('');
            },
          },
          t('keys_add')
        ),
        m(Button, { className: 'Button Button--primary', loading: this.saving, onclick: () => this.save() }, t('keys_save')),
      ]),
    ]);
  }
}
