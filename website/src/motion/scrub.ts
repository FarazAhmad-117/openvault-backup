// Scroll-linked progress. Sets --p (0..1) on every [data-scrub] element and, with data-stages="N", a data-stage index.
//   data-scrub="track": a tall pinned track (sticky child); 0 at pin start, 1 at pin end.
//   data-scrub="pass":  progress of the element crossing the viewport.
// One passive scroll listener, one rAF per frame. Only loaded on pages that contain [data-scrub].
import { passProgress, stageIndex, trackProgress } from '../lib/motion';

export function initScrub(signal: AbortSignal) {
  const els = [...document.querySelectorAll<HTMLElement>('[data-scrub]')];
  if (!els.length || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  let raf = 0;
  const last = new Map<HTMLElement, string>();
  const update = () => {
    raf = 0;
    const vh = innerHeight;
    for (const el of els) {
      const r = el.getBoundingClientRect();
      const p = el.dataset.scrub === 'pass' ? passProgress(r.top, r.height, vh) : trackProgress(r.top, r.height, vh);
      const v = p.toFixed(4);
      if (last.get(el) === v) continue;
      last.set(el, v);
      el.style.setProperty('--p', v);
      const n = Number(el.dataset.stages);
      if (n) el.dataset.stage = String(stageIndex(p, n));
    }
  };
  const schedule = () => { if (!raf) raf = requestAnimationFrame(update); };
  addEventListener('scroll', schedule, { passive: true, signal });
  addEventListener('resize', schedule, { signal });
  signal.addEventListener('abort', () => cancelAnimationFrame(raf));
  update();
}
