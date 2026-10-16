import { describe, it, expect } from 'vitest';
import { existsSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { distDir, hasDist, htmlFiles, route } from './helpers';

function resolves(path: string): boolean {
  const clean = decodeURIComponent(path.split(/[?#]/)[0]);
  const target = join(distDir, clean);
  if (!existsSync(target)) return false;
  return statSync(target).isDirectory() ? existsSync(join(target, 'index.html')) : true;
}

describe.skipIf(!hasDist)('internal links', () => {
  it('every internal href/src starting with / resolves to a built file', () => {
    const bad: string[] = [];
    for (const f of htmlFiles()) {
      const html = readFileSync(f, 'utf8');
      for (const m of html.matchAll(/\s(?:href|src)="(\/(?!\/)[^"]*)"/g)) {
        if (!resolves(m[1])) bad.push(`${route(f)} -> ${m[1]}`);
      }
    }
    expect(bad).toEqual([]);
  });

  it('in-page #fragment links point at an existing id', () => {
    const bad: string[] = [];
    for (const f of htmlFiles()) {
      const html = readFileSync(f, 'utf8');
      for (const m of html.matchAll(/\shref="#([^"]+)"/g)) {
        if (!html.includes(`id="${m[1]}"`)) bad.push(`${route(f)} -> #${m[1]}`);
      }
    }
    expect(bad).toEqual([]);
  });
});
