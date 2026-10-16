import { SITE_NAME, PLUGIN_VERSION, downloadUrl, siteUrl } from '../config/site';
import { features } from '../data/features';
import { canonical } from './seo';

const CONTEXT = 'https://schema.org';

export function softwareApplication(base?: string) {
  // Only features that genuinely ship may be claimed.
  const featureList = features.filter((f) => f.status === 'available').map((f) => f.title);
  return {
    '@context': CONTEXT,
    '@type': 'SoftwareApplication',
    name: SITE_NAME,
    applicationCategory: 'UtilitiesApplication',
    operatingSystem: 'WordPress',
    softwareVersion: PLUGIN_VERSION,
    downloadUrl: downloadUrl(),
    license: 'https://www.gnu.org/licenses/gpl-2.0.html',
    offers: { '@type': 'Offer', price: '0', priceCurrency: 'USD' },
    url: canonical(base ?? siteUrl(), '/'),
    ...(featureList.length > 0 ? { featureList } : {}),
  };
}

export function faqPage(items: { q: string; a: string }[]) {
  return {
    '@context': CONTEXT,
    '@type': 'FAQPage',
    mainEntity: items.map((i) => ({
      '@type': 'Question',
      name: i.q,
      acceptedAnswer: { '@type': 'Answer', text: i.a },
    })),
  };
}

export function howTo(name: string, steps: { name: string; text: string }[]) {
  return {
    '@context': CONTEXT,
    '@type': 'HowTo',
    name,
    step: steps.map((s, idx) => ({ '@type': 'HowToStep', position: idx + 1, name: s.name, text: s.text })),
  };
}

export function breadcrumbs(base: string, trail: { name: string; path: string }[]) {
  return {
    '@context': CONTEXT,
    '@type': 'BreadcrumbList',
    itemListElement: trail.map((t, idx) => ({
      '@type': 'ListItem',
      position: idx + 1,
      name: t.name,
      item: canonical(base, t.path),
    })),
  };
}
