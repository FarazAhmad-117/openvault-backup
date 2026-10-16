import { describe, it, expect } from 'vitest';
import { existsSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { distDir, hasDist, htmlFiles, route } from './helpers';
import { ogImagePath } from '../src/lib/seo';

describe('ogImagePath', () => {
  it('maps page paths to image paths', () => {
    expect(ogImagePath('/')).toBe('/og/index.png');
    expect(ogImagePath('/download/')).toBe('/og/download.png');
    expect(ogImagePath('/s3-r2/cloudflare-r2/')).toBe('/og/s3-r2/cloudflare-r2.png');
  });
});

describe.skipIf(!hasDist)('social images', () => {
  const files = htmlFiles();
  it('every page points og:image and twitter:image to its own existing 1200x630 PNG under 80 KB', () => {
    expect(files.length).toBeGreaterThan(0);
    for (const f of files) {
      const html = readFileSync(f, 'utf8');
      const og = /<meta property="og:image" content="([^"]+)"/.exec(html)?.[1];
      const tw = /<meta name="twitter:image" content="([^"]+)"/.exec(html)?.[1];
      expect(og, `${route(f)} og:image`).toBeTruthy();
      expect(tw, `${route(f)} twitter:image`).toBe(og);
      expect(html, route(f)).toContain('<meta property="og:image:width" content="1200"');
      expect(html, route(f)).toContain('<meta property="og:image:height" content="630"');
      expect(html, route(f)).toMatch(/<meta property="og:image:alt" content="[^"]+"/);
      const img = join(distDir, new URL(og!).pathname);
      expect(existsSync(img), `${route(f)} -> ${og} missing`).toBe(true);
      const buf = readFileSync(img);
      expect(buf.subarray(1, 4).toString(), img).toBe('PNG');
      expect(buf.readUInt32BE(16), img).toBe(1200);
      expect(buf.readUInt32BE(20), img).toBe(630);
      expect(statSync(img).size, img).toBeLessThan(80 * 1024);
    }
  });
});
