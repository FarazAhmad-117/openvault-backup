import { truncate } from './seo';

/** Guides whose canonical page lives outside /docs/ (no duplicate content). */
const HOMES: Record<string, string> = {
  'google-drive-setup': '/google-drive/setup-guide/',
  'cloudflare-r2': '/s3-r2/cloudflare-r2/',
  'backblaze-b2': '/s3-r2/backblaze-b2/',
  's3-compatible-setup': '/s3-r2/s3-compatible-setup/',
};

/** The single canonical URL of a guide. */
export const guideHref = (id: string): string => HOMES[id] ?? `/docs/${id}/`;

/** True when the guide is served at /docs/<id>/ (i.e. has no other canonical home). */
export const isDocsGuide = (id: string): boolean => !(id in HOMES);

const FALLBACK_TITLE = 'Setup guide';

/** Title from the first `# ` line; drops a trailing "(outline)" / "(Phase 3, outline)" and a leading "Guide: ". */
export function guideTitle(body: string): string {
  const line = (body ?? '').split(/\r?\n/).find((l) => /^#\s+\S/.test(l));
  if (!line) return FALLBACK_TITLE;
  const t = line
    .replace(/^#\s+/, '')
    .replace(/^Guide:\s*/i, '')
    .replace(/\s*\((?:[^)]*,\s*)?outline\)\s*$/i, '')
    .trim();
  return t || FALLBACK_TITLE;
}

const stripMarkdown = (s: string): string =>
  s
    .replace(/^\s*(?:\d+[.)]|[-*+])\s+/, '')
    .replace(/\[([^\]]+)\]\([^)]*\)/g, '$1')
    .replace(/[`*]/g, '')
    .replace(/(^|\s)_+|_+(?=\s|$)/g, '$1')
    .replace(/\s+/g, ' ')
    .trim();

/** Description from the first non-heading line, stripped of markdown and truncated to <=155 chars. */
export function guideDescription(body: string, fallback = 'An outline of the planned setup flow for OpenVault Backup.'): string {
  const line = (body ?? '').split(/\r?\n/).find((l) => l.trim() !== '' && !/^#{1,6}\s/.test(l));
  const text = line ? stripMarkdown(line) : '';
  return text ? truncate(text, 155) : fallback;
}
