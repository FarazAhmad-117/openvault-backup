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

export const htmlFiles = () => (hasDist ? walkAll().filter((f) => f.endsWith('.html')) : []);
export const route = (file: string) => '/' + relative(distDir, file).split(sep).join('/');
