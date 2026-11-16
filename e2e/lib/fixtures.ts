export interface Item {
  id: number;
  title: string;
  url: string;
}

export interface Fixtures {
  user: string;
  pages: Record<'html' | 'css' | 'palette' | 'aspetto' | 'dnd' | 'restore' | 'transfer' | 'security' | 'save' | 'api' | 'facets' | 'interactions' | 'review' | 'readonly', Item>;
  posts: Record<'post1' | 'post2' | 'post3', Item>;
}

/** What global-setup.ts created for this run. */
export function fixtures(): Fixtures {
  const raw = process.env.XB_E2E_FIXTURES;
  if (!raw) throw new Error('XB_E2E_FIXTURES is missing: run the suite with dev/e2e.sh');
  return JSON.parse(raw) as Fixtures;
}
