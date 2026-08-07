import app from 'flarum/forum/app';
import m from 'mithril';
// Statically imported ON PURPOSE: a dynamic import() becomes a separate
// webpack chunk, and Flarum's asset publisher only copies the named
// forum.js/admin.js bundles — the chunk 404s at runtime and the join
// spinner hangs forever.
import { Room, RoomEvent, Track } from 'livekit-client';

export interface Speaker {
  key: string;
  name: string;
  initial: string;
  color: string;
  speaking: boolean;
  muted: boolean;
  isLocal: boolean;
}

const PALETTE = ['#1ec3d6', '#b28cf5', '#e8b339', '#34c98e', '#f06a6a', '#5a9ded', '#e87fc0'];

/** Stable per-identity colour so a speaker keeps the same dot every session. */
function colorFor(key: string): string {
  let h = 0;
  for (let i = 0; i < key.length; i++) h = (h * 31 + key.charCodeAt(i)) >>> 0;
  return PALETTE[h % PALETTE.length];
}

/**
 * Connection state for the one live room the user can be in, plus a live
 * roster (who's on stage, who's talking, who's muted) so the bar can show
 * the room rather than just report that one exists.
 */
export default class ChirpState {
  room: any = null;
  discussionId: number | null = null;

  /** The room's server-truth recording flag (recorder bot present). */
  recording = false;

  /** Live speaker policy — data-channel truth once joined; null = use the
   *  discussion attribute. */
  speakPolicy: string | null = null;

  /** My raise-hand state in the current room. */
  handStatus: 'none' | 'pending' | 'approved' | 'declined' = 'none';

  /** Pending hands (host view). */
  hands: { userId: number; name: string }[] = [];
  /** Title + path of the room you're in, so the dock can label and link it. */
  roomTitle = '';
  roomPath = '';
  connecting = false;
  canPublish = false;
  muted = false;

  /** Identities currently talking, from LiveKit's speaker detection. */
  private active = new Set<string>();
  private audioEls: HTMLMediaElement[] = [];

  connected(): boolean {
    return !!this.room;
  }

  inDiscussion(id: number): boolean {
    return this.connected() && this.discussionId === id;
  }

  /** Is anyone talking right now? Drives the waveform's energy. */
  get anyoneSpeaking(): boolean {
    return this.active.size > 0;
  }

  /** Everyone who can publish audio — the stage. */
  speakers(): Speaker[] {
    if (!this.room) return [];

    const out: Speaker[] = [];
    const add = (p: any, isLocal: boolean) => {
      const publishes = isLocal ? this.canPublish : !!(p.permissions?.canPublish ?? (p.audioTrackPublications?.size ?? 0) > 0);
      if (!publishes) return;

      const key = String(p.identity ?? (isLocal ? 'me' : Math.random()));
      const name = String(p.name || p.identity || 'Speaker');
      const micOff = isLocal ? this.muted : ![...(p.audioTrackPublications?.values?.() ?? [])].some((pub: any) => !pub.isMuted);

      out.push({
        key,
        name,
        initial: (name.replace(/^[ug]\d+$/i, 'S').trim()[0] || 'S').toUpperCase(),
        color: colorFor(key),
        speaking: this.active.has(key),
        muted: micOff,
        isLocal,
      });
    };

    if (this.room.localParticipant) add(this.room.localParticipant, true);
    for (const p of this.room.remoteParticipants?.values?.() ?? []) add(p, false);

    return out;
  }

  /** Everyone else in the room — the audience (including yourself if listening).
   *  Counted off the roster, not room.numParticipants, whose local-participant
   *  semantics differ between client versions (it was over-counting by one). */
  listenerCount(): number {
    if (!this.room) return 0;
    const total = (this.room.remoteParticipants?.size ?? 0) + 1; // remotes + me
    return Math.max(0, total - this.speakers().length);
  }

  /** Remember where the current room lives (called by whoever initiates a join). */
  describe(title: string, path: string): void {
    this.roomTitle = title;
    this.roomPath = path;
  }

  async join(discussionId: number, speak: boolean): Promise<void> {
    if (this.connecting) return;
    this.connecting = true;
    m.redraw();

    try {
      const res = await app.request<any>({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/chirp/rooms/${discussionId}/token`,
        body: { speak },
      });

      await this.connect(discussionId, res.endpoint, res.token, !!res.canPublish);
    } finally {
      this.connecting = false;
      m.redraw();
    }
  }

  async connect(discussionId: number, endpoint: string, token: string, canPublish: boolean): Promise<void> {
    await this.leave();

    const room = new Room();
    const touch = () => m.redraw();

    room
      .on(RoomEvent.TrackSubscribed, (track: any) => {
        if (track.kind === Track.Kind.Audio) {
          const el = track.attach();
          el.style.display = 'none';
          document.body.appendChild(el);
          this.audioEls.push(el);
        }
        touch();
      })
      .on(RoomEvent.ActiveSpeakersChanged, (speakers: any[]) => {
        this.active = new Set(speakers.map((p) => String(p.identity)));
        touch();
      })
      .on(RoomEvent.ParticipantConnected, touch)
      .on(RoomEvent.ParticipantDisconnected, touch)
      .on(RoomEvent.TrackMuted, touch)
      .on(RoomEvent.TrackUnmuted, touch)
      .on(RoomEvent.TrackPublished, touch)
      .on(RoomEvent.TrackUnpublished, touch)
      // Driven by the recorder bot joining/leaving (its `recorder` grant
      // flips the room's ActiveRecording flag server-side) — the REC badge
      // is live truth, not a local guess.
      // Server-side stage moderation (host revoked our publish grant):
      // livekit unpublishes the mic; reflect it in the UI immediately.
      .on(RoomEvent.ParticipantPermissionsChanged, (_prev: any, participant: any) => {
        if (participant === this.room?.localParticipant && participant.permissions?.canPublish === false && this.canPublish) {
          this.canPublish = false;
          this.muted = false;
          if (this.handStatus === 'approved') this.handStatus = 'declined';
        }
        touch();
      })
      .on(RoomEvent.RecordingStatusChanged, (rec: boolean) => {
        this.recording = rec;
        touch();
      })
      .on(RoomEvent.DataReceived, (payload: Uint8Array) => this.onData(payload))
      .on(RoomEvent.Disconnected, () => {
        this.cleanup();
        m.redraw();
      });

    await room.connect(endpoint, token);
    this.recording = !!room.isRecording;

    this.room = room;
    this.discussionId = discussionId;
    this.canPublish = canPublish;
    this.muted = false;
    m.redraw();

    if (canPublish) {
      try {
        await room.localParticipant.setMicrophoneEnabled(true);
      } catch {
        app.alerts.show({ type: 'error' }, app.translator.trans('linkrobins-chirp.forum.mic_denied'));
        this.canPublish = false;
      }
      m.redraw();
    }
  }

  async setMuted(muted: boolean): Promise<void> {
    if (!this.room || !this.canPublish) return;
    await this.room.localParticipant.setMicrophoneEnabled(!muted);
    this.muted = muted;
    m.redraw();
  }

  async leave(): Promise<void> {
    if (this.room) {
      const room = this.room;
      this.cleanup();
      try {
        await room.disconnect();
      } catch {
        // already gone
      }
    }
  }

  private cleanup(): void {
    this.audioEls.forEach((el) => el.remove());
    this.audioEls = [];
    this.active = new Set();
    this.room = null;
    this.discussionId = null;
    this.roomTitle = '';
    this.roomPath = '';
    this.canPublish = false;
    this.muted = false;
    this.recording = false;
    this.speakPolicy = null;
    this.handStatus = 'none';
    this.hands = [];
  }

  // ── Speaker policies ───────────────────────────────────────────────────────
  // REST is the enforcement (the token endpoint checks rows); the data
  // channel only makes the other clients' UI instant.

  private api(): string {
    return String(app.forum.attribute('apiUrl'));
  }

  private myUserId(): number {
    return Number(app.session.user?.id() || 0);
  }

  private send(msg: Record<string, unknown>): void {
    try {
      this.room?.localParticipant.publishData(new TextEncoder().encode(JSON.stringify(msg)), { reliable: true });
    } catch {
      // UX sugar only — REST already landed.
    }
  }

  private onData(payload: Uint8Array): void {
    let msg: any;
    try {
      msg = JSON.parse(new TextDecoder().decode(payload));
    } catch {
      return;
    }

    switch (msg.t) {
      case 'hand':
        if (!this.hands.some((h) => h.userId === Number(msg.user))) {
          this.hands.push({ userId: Number(msg.user), name: String(msg.name || '?') });
        }
        break;
      case 'hand-ok':
        this.hands = this.hands.filter((h) => h.userId !== Number(msg.user));
        if (Number(msg.user) === this.myUserId() && this.handStatus !== 'approved') {
          this.handStatus = 'approved';
          // The mic was just unlocked for us — take it (same path as the
          // Speak button; reconnects with a publish token).
          if (this.discussionId && !this.canPublish) void this.join(this.discussionId, true);
        }
        break;
      case 'hand-no':
        this.hands = this.hands.filter((h) => h.userId !== Number(msg.user));
        if (Number(msg.user) === this.myUserId()) this.handStatus = 'declined';
        break;
      case 'policy':
        this.speakPolicy = String(msg.v);
        if (this.discussionId) {
          app.store.getById('discussions', String(this.discussionId))?.pushAttributes({ chirpSpeakPolicy: this.speakPolicy });
        }
        break;
      default:
        return;
    }
    m.redraw();
  }

  async raiseHand(discussionId: number): Promise<void> {
    await app.request({ method: 'POST', url: `${this.api()}/chirp/rooms/${discussionId}/hand` });
    this.handStatus = 'pending';
    this.send({ t: 'hand', user: this.myUserId(), name: app.session.user?.displayName() });
    m.redraw();
  }

  async resolveHand(discussionId: number, userId: number, approve: boolean): Promise<void> {
    await app.request({
      method: 'POST',
      url: `${this.api()}/chirp/rooms/${discussionId}/hand/${userId}`,
      body: { action: approve ? 'approve' : 'decline' },
    });
    this.hands = this.hands.filter((h) => h.userId !== userId);
    this.send({ t: approve ? 'hand-ok' : 'hand-no', user: userId });
    m.redraw();
  }

  async setPolicy(discussionId: number, policy: string): Promise<void> {
    await app.request({ method: 'POST', url: `${this.api()}/chirp/rooms/${discussionId}/policy`, body: { policy } });
    this.speakPolicy = policy;
    app.store.getById('discussions', String(discussionId))?.pushAttributes({ chirpSpeakPolicy: policy });
    this.send({ t: 'policy', v: policy });
    m.redraw();
  }

  /** Host moderation: 'unstage' revokes their mic, 'kick' removes them. */
  async moderate(discussionId: number, identity: string, action: 'unstage' | 'kick'): Promise<void> {
    await app.request({
      method: 'POST',
      url: `${this.api()}/chirp/rooms/${discussionId}/stage`,
      body: { identity, action },
    });
    m.redraw();
  }

  async loadHands(discussionId: number): Promise<void> {
    try {
      const res = await app.request<any>({ url: `${this.api()}/chirp/rooms/${discussionId}/hands` });
      this.hands = res?.hands || [];
      m.redraw();
    } catch {
      // Host UI just starts empty; data messages fill it in.
    }
  }
}
