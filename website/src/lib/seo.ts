import { SITE_NAME, TAGLINE, absoluteUrl } from '../config/site';

const MAX_TITLE = 70;

export function buildTitle(page?: string): string {
  if (page) return `${page} | ${SITE_NAME}`;
  const full = `${SITE_NAME}: ${TAGLINE}`;
  return full.length <= MAX_TITLE ? full : `${SITE_NAME} - Free WordPress backup plugin`;
}

export function truncate(text: string, max = 160): string {
  const clean = text.replace(/\s+/g, ' ').trim();
  if (clean.length <= max) return clean;
  const room = max - 1; // leave space for the ellipsis
  let cut = clean.slice(0, room);
  if (clean[room] !== ' ') {
    const lastSpace = cut.lastIndexOf(' ');
    if (lastSpace > 0) cut = cut.slice(0, lastSpace);
  }
  return cut.replace(/[\s,;:.-]+$/, '') + '…';
}

export function canonical(base: string, path: string): string {
  const clean = path.split(/[?#]/)[0];
  return absoluteUrl(base, clean).replace(/\/+$/, '') + '/';
}

/** An unconfigured deployment (no SITE_URL) must never be indexed. */
export function shouldNoindex(noindexProp: boolean, configured: boolean): boolean {
  return noindexProp || !configured;
}

/** Path of a page's generated social image (written by scripts/og.mjs after the build). */
export function ogImagePath(path: string): string {
  const slug = path.split(/[?#]/)[0].replace(/^\/+|\/+$/g, '');
  return `/og/${slug || 'index'}.png`;
}
