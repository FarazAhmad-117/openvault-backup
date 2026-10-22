import { describe, it, expect } from 'vitest';
import { readFileSync, statSync } from 'node:fs';
import { shots } from '../src/data/product';
import { hasDist, htmlFiles, route, walkAll } from './helpers';

describe('product screenshot data', () => {
  it('every screenshot has a descriptive alt and a caption', () => {
    for (const [name, s] of Object.entries(shots)) {
      expect(s.alt.length, `${name} alt`).toBeGreaterThan(30);
      expect(s.caption.length, `${name} caption`).toBeGreaterThan(3);
    }
  });

  it('screenshot alts never promise features that are not released (restore shipped in 0.3.0, so it is allowed)', () => {
    for (const [name, s] of Object.entries(shots)) {
      expect(`${s.alt} ${s.caption}`, name).not.toMatch(/\b(schedule|google drive|encrypt)/i);
    }
  });
});

describe.skipIf(!hasDist)('built images', () => {
  const files = htmlFiles();

  it('every <img> has an alt attribute (empty only for decoration)', () => {
    for (const f of files) {
      for (const [tag] of readFileSync(f, 'utf8').matchAll(/<img\b[^>]*>/g)) {
        expect(tag, route(f)).toMatch(/\salt=/);
      }
    }
  });

  it('the home hero image is the one eager, high-priority image and no other image is eager', () => {
    for (const f of files) {
      const tags = [...readFileSync(f, 'utf8').matchAll(/<img\b[^>]*>/g)].map((m) => m[0]);
      const eager = tags.filter((t) => !/loading="lazy"/.test(t));
      expect(eager.length, `${route(f)} has ${eager.length} non-lazy images`).toBeLessThanOrEqual(1);
      for (const t of eager) expect(t, route(f)).toMatch(/fetchpriority="high"/);
    }
  });

  // 0.3.0: four text-heavy retina admin screenshots (form, restore choose, restore preview as AVIF and WebP) compress past 100 KB; all stay under 180 KB and all but the hero are lazy.
  it('optimized images stay small: AVIF/WebP under 180 KB, at most four over 100 KB', () => {
    const imgs = walkAll().filter((f) => /_astro[\\/].*\.(avif|webp)$/.test(f));
    expect(imgs.length).toBeGreaterThan(0);
    const sizes = imgs.map((f) => statSync(f).size);
    for (const [i, s] of sizes.entries()) expect(s, imgs[i]).toBeLessThan(180 * 1024);
    expect(sizes.filter((s) => s > 100 * 1024).length).toBeLessThanOrEqual(4);
  });
});
