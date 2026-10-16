import { defineCollection } from 'astro:content';
import { glob } from 'astro/loaders';
import { z } from 'astro/zod';

// The guides are plain markdown outlines kept in the repo's docs/ folder (no frontmatter).
// If the folder is missing the loader only warns; the collection is then simply empty.
const guides = defineCollection({
  loader: glob({ pattern: '*.md', base: '../docs/guides' }),
  schema: z.object({}).passthrough(),
});

export const collections = { guides };
