// Scroll reveals. CSS (motion.css) owns the hidden/visible states; this only toggles `.is-in` and runs count-ups.
import { countValue } from '../lib/motion';

const COUNT_MS = 1400;

function countUp(el: HTMLElement, target: number, signal: AbortSignal) {
  const t0 = performance.now();
  let raf = 0;
  const tick = (now: number) => {
    el.textContent = String(countValue(target, now - t0, COUNT_MS));
    if (now - t0 < COUNT_MS) raf = requestAnimationFrame(tick);
  };
  raf = requestAnimationFrame(tick);
  signal.addEventListener('abort', () => { cancelAnimationFrame(raf); el.textContent = String(target); });
}

export function initReveal(signal: AbortSignal) {
  const els = [...document.querySelectorAll<HTMLElement>('[data-reveal]')];
  for (const el of els) {
    const d = el.dataset.revealDelay;
    if (d) el.style.setProperty('--d', d);
    if (el.dataset.reveal === 'stagger') [...el.children].forEach((c, i) => (c as HTMLElement).style.setProperty('--i', String(i)));
  }
  const counters = [...document.querySelectorAll<HTMLElement>('[data-count]')];
  const targets = new Map(counters.map((c) => [c, parseInt(c.textContent || '0', 10) || 0]));
  counters.forEach((c) => (c.textContent = '0'));

  const io = new IntersectionObserver(
    (entries) => {
      for (const e of entries) {
        if (!e.isIntersecting) continue;
        const el = e.target as HTMLElement;
        el.classList.add('is-in');
        if (targets.has(el)) countUp(el, targets.get(el)!, signal);
        io.unobserve(el);
      }
    },
    { rootMargin: '0px 0px -10% 0px', threshold: 0 },
  );
  [...els, ...counters].forEach((el) => io.observe(el));
  signal.addEventListener('abort', () => io.disconnect());
}
