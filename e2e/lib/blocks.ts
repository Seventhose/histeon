/** Minimal writer of XB block markup (docs/spec/blocks.md) for the fixture pages. */

let counter = 0;
const run = Math.random().toString(36).slice(2, 6).padEnd(4, '0');
/** 8+ lowercase alphanumerics, unique within a run. */
const uid = () => `e2e${run}${(counter++).toString(36).padStart(4, '0')}`;

const attrs = (value: Record<string, unknown>) => JSON.stringify(value).replace(/--/g, '\\u002d\\u002d');

export function text(content: string): string {
  return `<!-- wp:xb/text ${attrs({ uid: uid(), content })} /-->`;
}

export function el(tag: string, attributes: Record<string, string> | null, ...children: string[]): string {
  const a = attrs({ uid: uid(), tag, ...(attributes ? { attributes } : {}) });
  return `<!-- wp:xb/element ${a} -->${children.join('')}<!-- /wp:xb/element -->`;
}

/** {#if} with its branches (each `[test, ...children]`; a null test is the {:else}). */
export function ifBlock(...branches: Array<[string | null, ...string[]]>): string {
  const inner = branches
    .map(([test, ...children]) => `<!-- wp:xb/branch ${attrs({ uid: uid(), ...(test === null ? {} : { test }) })} -->${children.join('')}<!-- /wp:xb/branch -->`)
    .join('');
  return `<!-- wp:xb/if ${attrs({ uid: uid() })} -->${inner}<!-- /wp:xb/if -->`;
}
