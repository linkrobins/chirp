import app from 'flarum/admin/app';
import m from 'mithril';
import ChirpChannelsPanel from './components/ChirpChannelsPanel';
import ChirpKeysPanel from './components/ChirpKeysPanel';

// Chirp admin. The owner pastes channel keys from the linkrobins.com
// dashboard (one per purchased channel — each powers one standing voice
// channel plus one live broadcast at a time) and the extension exchanges
// each for its channel's connection config server-side. A plain-language
// status banner tells a no-experience user exactly where things stand.
app.initializers.add('linkrobins-chirp', () => {
  const banner = (): m.Children => {
    const t = (k: string) => app.translator.trans('linkrobins-chirp.admin.' + k);

    // Settings arrive as STRINGS ('1'/'0'); compare strictly against '1'.
    const connected = app.data.settings['linkrobins-chirp.connected'] === '1';

    return m(
      'div',
      { className: connected ? 'Alert Alert--success' : 'Alert', style: 'margin-bottom:16px;' },
      connected ? t('connected') : t('disconnected')
    );
  };

  app.registry
    .for('linkrobins-chirp')
    .registerSetting(banner, 100)
    // Multi-channel keys: self-saving list (each save runs the exchange).
    .registerSetting(() => m(ChirpKeysPanel), 90)
    .registerSetting({
      setting: 'linkrobins-chirp.default-speak-policy',
      label: app.translator.trans('linkrobins-chirp.admin.policy_label'),
      help: app.translator.trans('linkrobins-chirp.admin.policy_help'),
      type: 'select',
      options: {
        open: app.translator.trans('linkrobins-chirp.admin.policy_open'),
        hand: app.translator.trans('linkrobins-chirp.admin.policy_hand'),
        op: app.translator.trans('linkrobins-chirp.admin.policy_op'),
      },
      default: 'open',
    })
    .registerSetting({
      setting: 'linkrobins-chirp.record-rooms',
      label: app.translator.trans('linkrobins-chirp.admin.record_label'),
      help: app.translator.trans('linkrobins-chirp.admin.record_help'),
      type: 'boolean',
    })
    .registerSetting({
      setting: 'linkrobins-chirp.appearance',
      label: app.translator.trans('linkrobins-chirp.admin.appearance_label'),
      help: app.translator.trans('linkrobins-chirp.admin.appearance_help'),
      type: 'select',
      options: {
        brand: app.translator.trans('linkrobins-chirp.admin.appearance_brand'),
        forum: app.translator.trans('linkrobins-chirp.admin.appearance_forum'),
      },
      default: 'brand',
    })
    // Standing voice channels: designate/remove, admin-only by design.
    .registerSetting(() => m(ChirpChannelsPanel), -10)
    .registerPermission(
      {
        icon: 'fas fa-microphone',
        label: app.translator.trans('linkrobins-chirp.admin.permissions.go_live_label'),
        permission: 'discussion.chirpStart',
      },
      'moderate'
    )
    .registerPermission(
      {
        icon: 'fas fa-microphone-lines',
        label: app.translator.trans('linkrobins-chirp.admin.permissions.take_mic_label'),
        permission: 'discussion.chirpSpeak',
      },
      'reply'
    )
    .registerPermission(
      {
        icon: 'fas fa-trash-can',
        label: app.translator.trans('linkrobins-chirp.admin.permissions.delete_recording_label'),
        permission: 'discussion.chirpDeleteRecording',
      },
      'moderate'
    );
});
