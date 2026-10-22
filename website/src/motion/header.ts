// Persistent header: hide on scroll down / show on scroll up, blurred backdrop, sliding active indicator,
// full-screen mobile menu (focus trap, Esc, inert page behind, aria-expanded).
// The header is transition:persist and this module runs once, so listeners are bound once; update() runs after every navigation.
import { headerHidden, isCurrentPath } from '../lib/motion';

const SOLID_AT = 24;
const HIDE_AT = 140;

export function initHeader(): { update: (path: string) => void } {
  const header = document.querySelector<HTMLElement>('[data-header]');
  const btn = header?.querySelector<HTMLButtonElement>('[data-menu-btn]');
  const menu = header?.querySelector<HTMLElement>('#menu');
  if (!header || !btn || !menu) return { update() {} };

  const links = [...header.querySelectorAll<HTMLAnchorElement>('a[data-nav]')];
  const wrap = header.querySelector<HTMLElement>('.nav-links');
  const ind = header.querySelector<HTMLElement>('.nav-ind');
  const reduced = matchMedia('(prefers-reduced-motion: reduce)');
  const desktopLinks = wrap ? [...wrap.querySelectorAll<HTMLAnchorElement>('a[data-nav]')] : [];

  // ---- scroll state
  let prevY = scrollY, hidden = false, solid = false, raf = 0;
  const locked = () => header.classList.contains('menu-open') || header.matches(':focus-within');
  const frame = () => {
    raf = 0;
    const y = scrollY;
    const s = y > SOLID_AT;
    if (s !== solid) { solid = s; header.classList.toggle('is-solid', s); }
    const h = reduced.matches ? false : headerHidden(prevY, y, hidden, HIDE_AT, locked());
    if (h !== hidden) { hidden = h; header.toggleAttribute('data-hidden', h); }
    prevY = y;
  };
  addEventListener('scroll', () => { if (!raf) raf = requestAnimationFrame(frame); }, { passive: true });
  header.addEventListener('focusin', () => { if (hidden) { hidden = false; header.removeAttribute('data-hidden'); } });
  frame();

  // ---- sliding indicator
  const place = (a: HTMLElement | null) => {
    if (!ind) return;
    if (!a) { ind.classList.remove('on'); return; }
    ind.style.setProperty('--x', `${a.offsetLeft}px`);
    ind.style.setProperty('--w', String(a.offsetWidth)); // unitless: CSS scales a 1px-wide bar (no width animation)
    ind.classList.add('on');
  };
  const current = () => desktopLinks.find((a) => a.getAttribute('aria-current') === 'page') ?? null;
  desktopLinks.forEach((a) => {
    a.addEventListener('pointerenter', () => place(a));
    a.addEventListener('focus', () => place(a));
    a.addEventListener('blur', () => place(current()));
  });
  wrap?.addEventListener('pointerleave', () => place(current()));
  addEventListener('resize', () => place(current()));

  // ---- mobile menu
  const behind = () => [...document.body.children].filter((el) => el !== header && !el.matches('.curtain,.cursor,script,.astro-route-announcer')) as HTMLElement[];
  const own = [...header.querySelectorAll<HTMLElement>('.brand,.pill,.nav-desktop')];
  const focusables = () => [btn, ...menu.querySelectorAll<HTMLElement>('a[href]')];
  menu.inert = true;
  const setOpen = (open: boolean, returnFocus = true) => {
    header.classList.toggle('menu-open', open);
    document.documentElement.classList.toggle('menu-open', open);
    btn.setAttribute('aria-expanded', String(open));
    btn.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    menu.inert = !open;
    behind().forEach((el) => { el.inert = open; });
    own.forEach((el) => { el.inert = open; });
    if (open) { hidden = false; header.removeAttribute('data-hidden'); menu.querySelector<HTMLElement>('a')?.focus({ preventScroll: true }); }
    else if (returnFocus && header.contains(document.activeElement)) btn.focus({ preventScroll: true });
  };
  btn.addEventListener('click', () => setOpen(btn.getAttribute('aria-expanded') !== 'true'));
  document.addEventListener('keydown', (e) => {
    if (btn.getAttribute('aria-expanded') !== 'true') return;
    if (e.key === 'Escape') { e.preventDefault(); setOpen(false); return; }
    if (e.key !== 'Tab') return;
    const f = focusables();
    const first = f[0], last = f[f.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  });
  matchMedia('(min-width: 56rem)').addEventListener('change', (e) => { if (e.matches) setOpen(false, false); });

  return {
    update(path: string) {
      for (const a of links) {
        if (isCurrentPath(path, a.getAttribute('href') || '')) a.setAttribute('aria-current', 'page');
        else a.removeAttribute('aria-current');
      }
      if (btn.getAttribute('aria-expanded') === 'true') setOpen(false, false);
      place(current());
    },
  };
}
