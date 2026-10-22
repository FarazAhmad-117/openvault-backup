import { describe, it, expect } from 'vitest';
import { BOOT_SCRIPT, TIMING, clamp01, countValue, headerHidden, isCurrentPath, passProgress, stageIndex, trackProgress } from '../src/lib/motion';

type Env = { path?: string; hash?: string; reduced?: boolean; webdriver?: boolean; ua?: string; seen?: boolean; storageThrows?: boolean };

/** Runs the real inline boot script against a fake window and returns the html classes it left behind. */
function boot(env: Env = {}) {
  const classes = new Set<string>();
  const store: Record<string, string> = env.seen ? { 'ovb-preloader': '1' } : {};
  const timers: Array<() => void> = [];
  const w: any = {
    location: { pathname: env.path ?? '/', hash: env.hash ?? '' },
    navigator: { webdriver: !!env.webdriver, userAgent: env.ua ?? 'Mozilla/5.0 Firefox' },
    matchMedia: () => ({ matches: !!env.reduced }),
    sessionStorage: {
      getItem: (k: string) => { if (env.storageThrows) throw new Error('blocked'); return store[k] ?? null; },
      setItem: (k: string, v: string) => { store[k] = v; },
    },
  };
  w.setTimeout = (fn: () => void) => timers.push(fn);
  const d: any = { classList: { add: (c: string) => classes.add(c), remove: (c: string) => classes.delete(c) } };
  new Function('document', 'window', 'setTimeout', BOOT_SCRIPT)({ documentElement: d }, w, w.setTimeout);
  return { classes, store, runFailsafe: () => timers.forEach((t) => t()), w };
}

describe('boot script', () => {
  it('always adds js and plays the preloader on the first home entry', () => {
    const { classes, store } = boot();
    expect(classes.has('js')).toBe(true);
    expect(classes.has('preloading')).toBe(true);
    expect(store['ovb-preloader']).toBe('1');
  });
  it('plays once per session', () => {
    expect(boot({ seen: true }).classes.has('preloading')).toBe(false);
  });
  it('never plays off the home page, with a hash, with reduced motion, or for bots', () => {
    for (const env of [{ path: '/download/' }, { hash: '#main' }, { reduced: true }, { webdriver: true }, { ua: 'Googlebot/2.1' }, { ua: 'Chrome-Lighthouse' }]) {
      const { classes } = boot(env);
      expect(classes.has('js'), JSON.stringify(env)).toBe(true);
      expect(classes.has('preloading'), JSON.stringify(env)).toBe(false);
    }
  });
  it('survives blocked sessionStorage without throwing or playing', () => {
    const { classes } = boot({ storageThrows: true });
    expect(classes.has('js')).toBe(true);
    expect(classes.has('preloading')).toBe(false);
  });
  it('failsafe drops js and preloading when the runtime never started', () => {
    const t = boot();
    t.runFailsafe();
    expect(t.classes.has('js')).toBe(false);
    expect(t.classes.has('preloading')).toBe(false);
  });
  it('failsafe keeps js when the runtime started', () => {
    const t = boot({ seen: true });
    t.w.__ovb = 1;
    t.runFailsafe();
    expect(t.classes.has('js')).toBe(true);
  });
});

describe('timing budget', () => {
  it('page transition stays within 700 ms and the preloader within 1.6 s', () => {
    expect(TIMING.curtainIn + TIMING.curtainOut).toBeLessThanOrEqual(700);
    expect(TIMING.preloaderMax).toBeLessThanOrEqual(1600);
  });
});

describe('progress helpers', () => {
  it('clamps', () => {
    expect([clamp01(-1), clamp01(0.4), clamp01(9)]).toEqual([0, 0.4, 1]);
  });
  it('track progress runs 0..1 across the pinned distance', () => {
    expect(trackProgress(100, 2400, 800)).toBe(0);
    expect(trackProgress(-800, 2400, 800)).toBe(0.5);
    expect(trackProgress(-1600, 2400, 800)).toBe(1);
    expect(trackProgress(-9999, 2400, 800)).toBe(1);
    expect(trackProgress(0, 700, 800)).toBe(0);
  });
  it('pass progress runs 0..1 as an element crosses the viewport', () => {
    expect(passProgress(800, 400, 800)).toBe(0);
    expect(passProgress(-400, 400, 800)).toBe(1);
    expect(passProgress(200, 400, 800)).toBeCloseTo(0.5, 5);
  });
  it('stage index never leaves range and the last stage owns 1', () => {
    expect(stageIndex(0, 5)).toBe(0);
    expect(stageIndex(0.39, 5)).toBe(1);
    expect(stageIndex(1, 5)).toBe(4);
    expect(stageIndex(7, 5)).toBe(4);
  });
  it('count-up starts at 0, ends exactly on target, and is monotonic', () => {
    expect(countValue(9, 0, 1000)).toBe(0);
    expect(countValue(9, 1000, 1000)).toBe(9);
    expect(countValue(9, 5000, 1000)).toBe(9);
    let last = 0;
    for (let t = 0; t <= 1000; t += 50) {
      const v = countValue(40, t, 1000);
      expect(v).toBeGreaterThanOrEqual(last);
      last = v;
    }
    expect(countValue(3, 10, 0)).toBe(3);
  });
});

describe('nav + header rules', () => {
  it('matches current paths', () => {
    expect(isCurrentPath('/s3-r2/cloudflare-r2/', '/s3-r2/')).toBe(true);
    expect(isCurrentPath('/features/', '/s3-r2/')).toBe(false);
    expect(isCurrentPath('/features/', '/')).toBe(false);
    expect(isCurrentPath('/', '/')).toBe(true);
    expect(isCurrentPath('', '/')).toBe(false);
  });
  it('hides on scroll down, shows on scroll up, ignores jitter, never hides near the top or while locked', () => {
    expect(headerHidden(300, 340, false, 80, false)).toBe(true);
    expect(headerHidden(340, 300, true, 80, false)).toBe(false);
    expect(headerHidden(300, 302, true, 80, false)).toBe(true);
    expect(headerHidden(10, 60, false, 80, false)).toBe(false);
    expect(headerHidden(300, 340, false, 80, true)).toBe(false);
  });
});
