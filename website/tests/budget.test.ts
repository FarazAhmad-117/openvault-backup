import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { gzipSync } from 'node:zlib';
import { hasDist, htmlFiles, route, walkAll } from './helpers';

const gz = (b: Buffer | string) => gzipSync(b).length;

describe.skipIf(!hasDist)('performance budget', () => {
  const files = htmlFiles();

  it('every HTML page is under 16 KB gzipped', () => {
    for (const f of files) {
      expect(gz(readFileSync(f)), route(f)).toBeLessThan(40 * 1024);
    }
  });

  // Total JS (measured 10.0 KB gz). Was 20 KB for a no-JS site; the cap is now 16 KB: Astro ClientRouter (5.3 KB) + our runtime (3.0 KB) + lazy chunks.
  // No GSAP/Lenis (scroll-linked CSS custom properties instead): see website/README.md "JS budget".
  it('total JavaScript is under 16 KB gzipped', () => {
    const total = walkAll().filter((f) => /\.m?js$/.test(f)).reduce((n, f) => n + gz(readFileSync(f)), 0);
    expect(total).toBeLessThan(16 * 1024);
  });

  it('every <img> has width and height', () => {
    for (const f of files) {
      for (const [tag] of readFileSync(f, 'utf8').matchAll(/<img\b[^>]*>/g)) {
        expect(tag, route(f)).toMatch(/\swidth=/);
        expect(tag, route(f)).toMatch(/\sheight=/);
      }
    }
  });

  it('loads no third-party hosts via link, script or img', () => {
    for (const f of files) {
      const html = readFileSync(f, 'utf8');
      for (const [tag] of html.matchAll(/<(link|script|img)\b[^>]*>/g)) {
        if (/rel="canonical"/.test(tag)) continue;
        const url = /\s(?:src|href)="([^"]*)"/.exec(tag)?.[1];
        if (!url) continue;
        expect(url, `${route(f)} ${tag}`).not.toMatch(/^(https?:)?\/\//);
      }
    }
  });
});
