// Page transition: a brass shutter closes over the old page (leave), the router swaps while it is closed,
// then it opens over the new page (enter). Total ~680 ms. Reduced motion: no animation, plain swap.
// Hooks into Astro's <ClientRouter /> events; listeners are bound once (the document lives for the whole session).
import { TIMING } from '../lib/motion';

const EASE = 'cubic-bezier(0.76, 0, 0.24, 1)';

export function initTransitions() {
  let covered = false;
  let failsafe = 0;
  const reduced = () => matchMedia('(prefers-reduced-motion: reduce)').matches;
  const curtain = () => document.getElementById('curtain');
  const strips = () => [...(curtain()?.children ?? [])] as HTMLElement[];

  const run = (from: string, to: string, origin: string, dur: number, stagger: number) =>
    Promise.all(
      strips().map((s, i) => s.animate(
        [{ transform: `scaleY(${from})`, transformOrigin: origin }, { transform: `scaleY(${to})`, transformOrigin: origin }],
        { duration: dur, delay: i * stagger, easing: EASE, fill: 'forwards' },
      ).finished),
    );

  const reveal = async () => {
    const c = curtain();
    if (!c || !covered) return;
    covered = false;
    clearTimeout(failsafe);
    await run('1', '0', 'bottom', 220, 28);
    c.getAnimations({ subtree: true }).forEach((a) => a.cancel());
    c.classList.remove('is-on');
  };

  const cover = async () => {
    const c = curtain();
    if (!c) return;
    c.classList.add('is-on');
    covered = true;
    clearTimeout(failsafe);
    failsafe = window.setTimeout(reveal, TIMING.curtainFailsafe);
    await run('0', '1', 'top', 200, 24);
  };

  document.addEventListener('astro:before-preparation', (ev) => {
    if (reduced() || !curtain()) return;
    const e = ev as Event & { loader: () => Promise<void> };
    const load = e.loader;
    e.loader = async () => { await cover(); await load(); };
  });
  document.addEventListener('astro:page-load', () => { void reveal(); });
}
