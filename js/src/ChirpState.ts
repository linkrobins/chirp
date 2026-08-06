import app from 'flarum/forum/app';
import m from 'mithril';
// Statically imported ON PURPOSE: a dynamic import() becomes a separate
// webpack chunk, and Flarum's asset publisher only copies the named
// forum.js/admin.js bundles — the chunk 404s at runtime and the join
// spinner hangs forever (caught in the first real-device test).
import { Room, RoomEvent } from 'livekit-client';

/**
 * Connection state for the one live room the user can be in.
 */
export default class ChirpState {
  room: any = null;
  discussionId: number | null = null;
  connecting = false;
  canPublish = false;
  muted = false;
  participantCount = 0;

  private audioEls: HTMLMediaElement[] = [];

  connected(): boolean {
    return !!this.room;
  }

  inDiscussion(id: number): boolean {
    return this.connected() && this.discussionId === id;
  }

  /** Join (or re-join with a mic request) the live room on a discussion. */
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

  /** Connect with an already-minted token (the go-live path returns one). */
  async connect(discussionId: number, endpoint: string, token: string, canPublish: boolean): Promise<void> {
    await this.leave();

    const room = new Room();

    room
      .on(RoomEvent.TrackSubscribed, (track: any) => {
        if (track.kind === 'audio') {
          const el = track.attach();
          el.style.display = 'none';
          document.body.appendChild(el);
          this.audioEls.push(el);
        }
      })
      .on(RoomEvent.ParticipantConnected, () => this.syncCount(room))
      .on(RoomEvent.ParticipantDisconnected, () => this.syncCount(room))
      .on(RoomEvent.Disconnected, () => {
        this.cleanup();
        m.redraw();
      });

    await room.connect(endpoint, token);

    this.room = room;
    this.discussionId = discussionId;
    this.canPublish = canPublish;
    this.muted = false;
    this.syncCount(room);

    if (canPublish) {
      try {
        await room.localParticipant.setMicrophoneEnabled(true);
      } catch {
        app.alerts.show({ type: 'error' }, app.translator.trans('linkrobins-chirp.forum.mic_denied'));
        this.canPublish = false;
      }
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

  private syncCount(room: any): void {
    this.participantCount = (room.numParticipants ?? 0) + 1; // + self
    m.redraw();
  }

  private cleanup(): void {
    this.audioEls.forEach((el) => el.remove());
    this.audioEls = [];
    this.room = null;
    this.discussionId = null;
    this.canPublish = false;
    this.muted = false;
    this.participantCount = 0;
  }
}
