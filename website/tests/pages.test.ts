import { describe, it, expect } from 'vitest';
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative, sep } from 'node:path';

const distDir = join(process.cwd(), 'dist');

function walk(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const p = join(dir, name);
    return statSync(p).isDirectory() ? walk(p) : p.endsWith('.html') && !/[\\/]google[0-9a-f]+\.html$/.test(p) ? [p] : [];
  });
}

const route = (file: string) => '/' + relative(distDir, file).split(sep).join('/');
const is404 = (file: string) => route(file) === '/404.html';

const first = (html: string, re: RegExp) => re.exec(html)?.[1];
const unescape = (s: string) => s.replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>');

describe.skipIf(!existsSync(distDir))('built pages', () => {
  const files = existsSync(distDir) ? walk(distDir) : [];
  const pages = files.filter((f) => !is404(f));
  const html = new Map(files.map((f) => [f, readFileSync(f, 'utf8')]));

  it('finds pages to check', () => {
    expect(pages.length).toBeGreaterThan(0);
  });

  it('every page has exactly one h1', () => {
    for (const f of pages) {
      expect(html.get(f)!.match(/<h1[\s>]/g)?.length ?? 0, route(f)).toBe(1);
    }
  });

  it('every page has a non-empty, unique title', () => {
    const seen = new Map<string, string>();
    for (const f of pages) {
      const t = first(html.get(f)!, /<title>([\s\S]*?)<\/title>/)?.trim();
      expect(t, route(f)).toBeTruthy();
      expect(seen.has(t!), `${route(f)} duplicates the title of ${seen.get(t!)}`).toBe(false);
      seen.set(t!, route(f));
    }
  });

  it('every page has a unique meta description of 1-160 chars', () => {
    const seen = new Map<string, string>();
    for (const f of pages) {
      const tag = /<meta name="description" content="([^"]*)"/.exec(html.get(f)!);
      expect(tag, `${route(f)} has no description`).not.toBeNull();
      const d = unescape(tag![1]);
      expect(d.length, route(f)).toBeGreaterThanOrEqual(1);
      expect(d.length, route(f)).toBeLessThanOrEqual(160);
      expect(seen.has(d), `${route(f)} duplicates the description of ${seen.get(d)}`).toBe(false);
      seen.set(d, route(f));
    }
  });

  it('every page has a canonical link', () => {
    for (const f of pages) {
      expect(html.get(f)!, route(f)).toMatch(/<link rel="canonical" href="https?:\/\/[^"]+"/);
    }
  });

  it('every JSON-LD block parses as JSON', () => {
    for (const f of files) {
      const blocks = [...html.get(f)!.matchAll(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/g)];
      for (const b of blocks) {
        expect(() => JSON.parse(b[1]), route(f)).not.toThrow();
      }
    }
  });

  it.skipIf(!!process.env.PUBLIC_GSC_VERIFICATION)('has no google-site-verification when the env var is unset', () => {
    for (const f of files) {
      expect(html.get(f)!, route(f)).not.toContain('google-site-verification');
    }
  });

  it('only uses the placeholder domain in canonical, og and twitter URLs', () => {
    for (const f of files) {
      const stripped = html.get(f)!
        .replace(/<link rel="canonical"[^>]*>/g, '')
        .replace(/<meta (property|name)="(og|twitter):[^>]*>/g, '')
        .replace(/<script type="application\/ld\+json">[\s\S]*?<\/script>/g, '');
      expect(stripped, route(f)).not.toContain('example.invalid');
    }
  });

  it('the 404 page is noindex', () => {
    const f = files.find(is404);
    expect(f, 'dist/404.html missing').toBeTruthy();
    expect(html.get(f!)!).toMatch(/<meta name="robots" content="[^"]*noindex/);
  });
});
