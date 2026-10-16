import { existsSync, readdirSync, statSync } from 'node:fs';
import { join, relative, sep } from 'node:path';

export const distDir = join(process.cwd(), 'dist');
export const hasDist = existsSync(distDir);

export function walkAll(dir: string = distDir): string[] {
  return readdirSync(dir).flatMap((name) => {
    const p = join(dir, name);
    return statSync(p).isDirectory() ? walkAll(p) : [p];
  });
}

// Google Search Console verification files (googleXXXX.html) are not pages.
export const isVerificationFile = (f: string) => /[\\/]google[0-9a-f]+\.html$/.test(f);
export const htmlFiles = () => (hasDist ? walkAll().filter((f) => f.endsWith('.html') && !isVerificationFile(f)) : []);
export const route = (file: string) => '/' + relative(distDir, file).split(sep).join('/');
