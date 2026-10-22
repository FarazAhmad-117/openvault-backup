// Build-time headline splitting for PageHead: balanced display lines.
/** Split a title into 1..4 lines of similar length (minimum raggedness). The words stay in order, nothing is dropped. */
export const splitTitle = (title: string): string[] => {
  const words = title.trim().split(/\s+/).filter(Boolean);
  const total = words.join(' ').length;
  const n = Math.min(words.length, total <= 14 ? 1 : total <= 30 ? 2 : total <= 48 ? 3 : 4);
  if (n <= 1) return [words.join(' ')];
  const target = total / n;
  const len = (i: number, j: number) => words.slice(i, j).join(' ').length;
  // best[k][j] = least cost to set words[0..j) in k lines (cost = squared distance from the target line length)
  const best: number[][] = Array.from({ length: n + 1 }, () => Array(words.length + 1).fill(Infinity));
  const cut: number[][] = Array.from({ length: n + 1 }, () => Array(words.length + 1).fill(0));
  best[0][0] = 0;
  for (let k = 1; k <= n; k++) {
    for (let j = k; j <= words.length; j++) {
      for (let i = k - 1; i < j; i++) {
        const c = best[k - 1][i] + (len(i, j) - target) ** 2;
        if (c < best[k][j]) { best[k][j] = c; cut[k][j] = i; }
      }
    }
  }
  const lines: string[] = [];
  for (let k = n, j = words.length; k > 0; j = cut[k][j], k--) lines.unshift(words.slice(cut[k][j], j).join(' '));
  return lines;
};

/** Size tier for the page headline, from its longest line. */
export const headTier = (lines: string[]): 'xl' | 'lg' | 'md' => {
  const m = Math.max(...lines.map((l) => l.length));
  return m <= 13 ? 'xl' : m <= 19 ? 'lg' : 'md';
};
