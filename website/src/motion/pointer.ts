// Fine-pointer effects: cursor follower (additive: the system cursor stays), magnetic buttons, spotlight hover.
// Only loaded for (hover: hover) and (pointer: fine) without reduced motion.
let cursorBound = false;

const TEXT_INPUT = 'input:not([type=checkbox]):not([type=radio]):not([type=button]):not([type=submit]):not([type=range]),textarea,select,[contenteditable=""],[contenteditable="true"]';
const HOVERABLE = 'a[href],button,summary,[role=button],[data-cursor]';

function bindCursor() {
  const el = document.getElementById('cursor');
  if (!el || cursorBound) return;
  cursorBound = true;
  const label = el.querySelector<HTMLElement>('.cursor-label')!;
  let x = 0, y = 0, cx = 0, cy = 0, raf = 0, seen = false;
  const loop = () => {
    cx += (x - cx) * 0.22;
    cy += (y - cy) * 0.22;
    el.style.transform = `translate3d(${cx.toFixed(1)}px,${cy.toFixed(1)}px,0)`;
    raf = Math.abs(x - cx) + Math.abs(y - cy) > 0.3 ? requestAnimationFrame(loop) : 0;
  };
  addEventListener('pointermove', (e) => {
    if (e.pointerType !== 'mouse') return;
    x = e.clientX; y = e.clientY;
    if (!seen) { seen = true; cx = x; cy = y; }
    const t = e.target as Element | null;
    const hidden = !!t?.closest(TEXT_INPUT);
    const hit = t?.closest<HTMLElement>(HOVERABLE);
    const text = t?.closest<HTMLElement>('[data-cursor]')?.dataset.cursor || '';
    el.classList.toggle('is-on', true);
    el.classList.toggle('is-hidden', hidden);
    el.classList.toggle('is-link', !!hit && !text);
    el.classList.toggle('is-label', !!text);
    if (label.textContent !== text) label.textContent = text;
    if (!raf) raf = requestAnimationFrame(loop);
  }, { passive: true });
  document.documentElement.addEventListener('pointerleave', () => el.classList.remove('is-on'));
  addEventListener('blur', () => el.classList.remove('is-on'));
}

export function initPointer(signal: AbortSignal) {
  bindCursor();
  for (const el of document.querySelectorAll<HTMLElement>('[data-magnetic]')) {
    el.addEventListener('pointermove', (e) => {
      const r = el.getBoundingClientRect();
      const dx = e.clientX - (r.left + r.width / 2), dy = e.clientY - (r.top + r.height / 2);
      el.style.transform = `translate3d(${(dx * 0.28).toFixed(1)}px,${(dy * 0.4).toFixed(1)}px,0)`;
    }, { signal });
    el.addEventListener('pointerleave', () => { el.style.transform = ''; }, { signal });
  }
  for (const el of document.querySelectorAll<HTMLElement>('[data-spot]')) {
    el.addEventListener('pointermove', (e) => {
      const r = el.getBoundingClientRect();
      el.style.setProperty('--mx', `${(e.clientX - r.left).toFixed(0)}px`);
      el.style.setProperty('--my', `${(e.clientY - r.top).toFixed(0)}px`);
    }, { signal, passive: true });
  }
}
