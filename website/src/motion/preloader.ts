// Preloader behaviour: skip (Esc, click, button) and focus clean-up. The animation itself is CSS (motion.css).
import { TIMING } from '../lib/motion';

export function initPreloader() {
  const root = document.documentElement;
  const pl = document.getElementById('preloader');
  if (!pl || !root.classList.contains('preloading')) return;
  const t0 = performance.now();
  const ac = new AbortController();
  let finished = false;

  const finish = () => {
    if (finished) return;
    finished = true;
    ac.abort();
    if (pl.contains(document.activeElement)) document.getElementById('main')?.focus({ preventScroll: true });
    pl.remove();
  };
  const skip = () => {
    // before the shutter starts, also release the held hero reveal; afterwards just hide
    if (performance.now() - t0 < 1000) root.classList.add('pl-skipped');
    else pl.style.display = 'none';
    finish();
  };
  pl.addEventListener('click', skip, { signal: ac.signal });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') skip(); }, { signal: ac.signal });
  setTimeout(finish, TIMING.preloaderMax + 60);
}
