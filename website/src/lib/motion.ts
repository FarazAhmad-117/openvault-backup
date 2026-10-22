// Pure motion logic (no DOM access at import time) so it can be unit tested.
// CSS durations in src/styles/motion.css must match TIMING (tests/motion.test.ts guards the totals).

export const TIMING = {
  /** Preloader hard cap in ms (the CSS timeline in motion.css ends at the same time). */
  preloaderMax: 1600,
  /** Page transition: curtain closes over the old page, opens over the new one. */
  curtainIn: 320,
  curtainOut: 360,
  /** If the curtain is still closed after this long something went wrong: open it. */
  curtainFailsafe: 3000,
  /** `js` class is removed again if the motion runtime has not started by then. */
  bootFailsafe: 4000,
} as const;

/**
 * Inline <head> script (kept as a string so it can be inlined AND tested).
 * 1. adds `js` (every hidden-until-reveal state in motion.css lives under html.js)
 * 2. decides whether the preloader plays: home page entry, first visit this session,
 *    no reduced motion, not a bot/webdriver, no #hash
 * 3. failsafe: if the runtime never starts, drops `js` so nothing stays hidden
 */
export const BOOT_SCRIPT = `(function(d,w){var c=d.classList;c.add('js');try{var m=w.matchMedia&&w.matchMedia('(prefers-reduced-motion: reduce)').matches,n=w.navigator,bot=n.webdriver||/bot|crawl|spider|lighthouse|headless/i.test(n.userAgent),k='ovb-preloader';if(w.location.pathname==='/'&&!w.location.hash&&!m&&!bot&&!w.sessionStorage.getItem(k)){c.add('preloading');w.sessionStorage.setItem(k,'1')}}catch(e){}setTimeout(function(){if(!w.__ovb)c.remove('js');c.remove('preloading')},${TIMING.bootFailsafe})})(document.documentElement,window)`;

export const clamp01 = (n: number): number => (n < 0 ? 0 : n > 1 ? 1 : n);

/** Scroll progress (0..1) of a pinned track: 0 when its top reaches the viewport top, 1 when its bottom reaches the viewport bottom. */
export const trackProgress = (rectTop: number, trackHeight: number, viewportHeight: number): number => {
  const span = trackHeight - viewportHeight;
  return span <= 0 ? 0 : clamp01(-rectTop / span);
};

/** Progress (0..1) of an element passing through the viewport: 0 when it enters at the bottom, 1 when it leaves at the top. */
export const passProgress = (rectTop: number, elHeight: number, viewportHeight: number): number =>
  clamp01((viewportHeight - rectTop) / (viewportHeight + elHeight));

/** Index of the active stage (0..count-1) for a progress value; the last stage owns progress 1. */
export const stageIndex = (p: number, count: number): number => Math.min(count - 1, Math.floor(clamp01(p) * count));

export const easeOutCubic = (t: number): number => 1 - Math.pow(1 - clamp01(t), 3);

/** Value shown by a count-up `elapsed` ms into a `duration` ms animation toward `target`. */
export const countValue = (target: number, elapsed: number, duration: number): number =>
  Math.round(target * easeOutCubic(duration <= 0 ? 1 : elapsed / duration));

/** Same rule the Header uses at build time and again after a client-side navigation. */
export const isCurrentPath = (current: string, href: string): boolean =>
  current !== '' && (href === '/' ? current === '/' : current === href || current.startsWith(href));

/** Header hides while scrolling down past `threshold`, shows on any upward scroll, and never hides while pinned open. */
export const headerHidden = (prevY: number, y: number, prevHidden: boolean, threshold: number, locked: boolean): boolean => {
  if (locked || y <= threshold) return false;
  const dy = y - prevY;
  if (Math.abs(dy) < 6) return prevHidden;
  return dy > 0;
};
