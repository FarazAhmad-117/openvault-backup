import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';

const site = process.env.SITE_URL || 'https://example.invalid';

const textOf = (n) => (n.type === 'text' ? n.value : (n.children ?? []).map(textOf).join(''));

/**
 * Rehype plugin (no dependencies): the guide files start with their own `# Title (outline)`
 * heading, but each page's single <h1> comes from PageHead, so drop the first h1.
 * It also drops the maintainers' note line that starts with "Screenshots:" (a file-path
 * placeholder, not reader content).
 */
function removeGuideHeading() {
  return (tree) => {
    let removedH1 = false;
    const walk = (node) => {
      if (!node.children) return;
      node.children = node.children.filter((child) => {
        if (!removedH1 && child.type === 'element' && child.tagName === 'h1') {
          removedH1 = true;
          return false;
        }
        if (child.type === 'element' && child.tagName === 'p' && /^\s*Screenshots:/.test(textOf(child))) return false;
        return true;
      });
      node.children.forEach(walk);
    };
    walk(tree);
  };
}

export default defineConfig({
  site,
  output: 'static',
  trailingSlash: 'always',
  build: { format: 'directory' },
  integrations: [sitemap()],
  markdown: { rehypePlugins: [removeGuideHeading] },
});
