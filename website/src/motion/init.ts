// Motion runtime entry (loaded once from Base.astro; survives client-side navigations).
// - per-page work is (re)initialised on `astro:page-load` and torn down on `astro:before-swap`
// - optional parts are dynamic imports: scrub (only if the page has [data-scrub]), pointer (fine pointers only), preloader
import { initHeader } from './header';
import { initReveal } from './reveal';
import { initTransitions } from './transitions';

const root = document.documentElement;

// `js` is added by the inline boot script; if its failsafe already removed it, stay out of the way.
if (root.classList.contains('js')) {
  (window as unknown as { __ovb: number }).__ovb = 1;
  const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const finePointer = matchMedia('(hover: hover) and (pointer: fine)').matches;
  const header = initHeader();
  initTransitions();

  // the router replaces <html> attributes on swap, which would drop our class
  document.addEventListener('astro:after-swap', () => root.classList.add('js'));

  let ctrl: AbortController | null = null;
  let first = true;
  document.addEventListener('astro:before-swap', () => ctrl?.abort());
  document.addEventListener('astro:page-load', () => {
    ctrl?.abort();
    ctrl = new AbortController();
    const { signal } = ctrl;
    header.update(location.pathname);

    if (!reduced) {
      initReveal(signal);
      if (document.querySelector('[data-scrub]')) import('./scrub').then((m) => m.initScrub(signal));
      if (finePointer) import('./pointer').then((m) => m.initPointer(signal));
      if (first && root.classList.contains('preloading')) import('./preloader').then((m) => m.initPreloader());
    }

    // back to top (a plain #top link without JS)
    document.querySelectorAll<HTMLAnchorElement>('[data-top]').forEach((a) =>
      a.addEventListener('click', (e) => {
        e.preventDefault();
        scrollTo({ top: 0, behavior: reduced ? 'auto' : 'smooth' });
        document.getElementById('main')?.focus({ preventScroll: true });
      }, { signal }),
    );

    // a #hash that points at (or into) a closed <details> opens it, so FAQ anchors work
    const target = location.hash.length > 1 ? document.getElementById(decodeURIComponent(location.hash.slice(1))) : null;
    const det = target?.closest('details');
    if (det) det.open = true;

    // after a client-side navigation, move focus to the new content (Astro's route announcer reads the title)
    if (!first) document.getElementById('main')?.focus({ preventScroll: true });
    first = false;
  });
}
