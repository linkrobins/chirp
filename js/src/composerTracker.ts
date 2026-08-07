/**
 * Shared composer plumbing for the docked bars (live ChirpBar and the
 * recording bar): publishes how much of the bottom edge Flarum's composer
 * covers as --chirp-composer-h (the docked bar rides above it) and flags
 * chirp-composer-full when the composer takes over most of the screen (the
 * bar steps aside). One bar exists at a time — live XOR recording — so the
 * shared globals can't fight; each bar contributes its own html marker
 * class (chirp-live / chirp-recorded) for the content-padding rules.
 *
 * NB: measure `.Composer` (the actual overlay, created lazily when a
 * composer opens), not `.App-composer` — that wrapper is a zero-height
 * placeholder parked at the end of the document. Needs BOTH a
 * MutationObserver (the element appears without re-rendering the bar) and a
 * ResizeObserver (open/minimise animations finish after the redraw).
 */
export default class ComposerTracker {
  private composerWatch?: ResizeObserver;
  private domWatch?: MutationObserver;

  constructor(private marker: string) {}

  start(): void {
    document.documentElement.classList.add(this.marker);
    this.update();
    this.domWatch = new MutationObserver(() => this.update());
    this.domWatch.observe(document.body, { childList: true, subtree: true });
  }

  update(): void {
    const composer = document.querySelector('.Composer') as HTMLElement | null;
    const root = document.documentElement;

    if (!composer) {
      root.style.setProperty('--chirp-composer-h', '0px');
      root.classList.remove('chirp-composer-full');
      this.composerWatch?.disconnect();
      this.composerWatch = undefined;
      return;
    }

    const measure = () => {
      const rect = composer.getBoundingClientRect();
      const style = getComputedStyle(composer);
      const hidden = style.display === 'none' || style.visibility === 'hidden' || rect.height === 0;
      // Only what actually covers the bottom edge pushes the bar up.
      const anchoredToBottom = !hidden && rect.bottom >= window.innerHeight - 2;
      const covered = anchoredToBottom ? Math.max(0, Math.round(window.innerHeight - rect.top)) : 0;

      root.style.setProperty('--chirp-composer-h', `${covered}px`);
      root.classList.toggle('chirp-composer-full', covered > window.innerHeight * 0.4);
    };

    measure();

    if (!this.composerWatch && 'ResizeObserver' in window) {
      // Keeps up with the open/minimise animations, which finish long after
      // the redraw that triggered them.
      this.composerWatch = new ResizeObserver(measure);
      this.composerWatch.observe(composer);
    }
  }

  stop(): void {
    document.documentElement.classList.remove(this.marker, 'chirp-composer-full');
    document.documentElement.style.removeProperty('--chirp-composer-h');
    this.composerWatch?.disconnect();
    this.composerWatch = undefined;
    this.domWatch?.disconnect();
    this.domWatch = undefined;
  }
}
