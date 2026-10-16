import type { APIRoute } from 'astro';
import { absoluteUrl, isConfiguredSite, siteUrl } from '../config/site';

export const GET: APIRoute = () => {
  const body = isConfiguredSite()
    ? `User-agent: *\nAllow: /\n\nSitemap: ${absoluteUrl(siteUrl(), '/sitemap-index.xml')}\n`
    : 'User-agent: *\nDisallow: /\n';
  return new Response(body, { headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
};
