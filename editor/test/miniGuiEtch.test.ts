import { describe, expect, it } from 'vitest';
import { declarations, setDeclaration } from '../src/lib/miniGui.ts';
import { ETCH_GROUPS, etchGroups, snippetChanges, snippetState } from '../src/lib/miniGuiEtch.ts';

const ids = (css: string, tag = 'div', state: string | null = null) => etchGroups({ decls: declarations(css), tag, state }).map((g) => g.id);
const snippet = (group: string, id: string) => ETCH_GROUPS.find((g) => g.id === group)!.snippets.find((s) => s.id === id)!;
function press(css: string, group: string, id: string): string {
  let next = css;
  for (const [prop, value] of snippetChanges(snippet(group, id), declarations(css))) next = setDeclaration(next, prop, value);
  return next;
}

describe('mini GUI in Etch style', () => {
  it('shows a group only where it makes sense, as Etch does', () => {
    expect(ids('')).toEqual(['display', 'transition', 'position', 'box']);
    expect(ids('display: grid;')).toContain('grid');
    expect(ids('display: grid;')).toContain('align');
    expect(ids('display: grid;')).not.toContain('justify');
    expect(ids('display: flex;')).toEqual(expect.arrayContaining(['justify', 'align']));
    expect(ids('', 'h2')).toContain('text');
    expect(ids('', 'img')).toContain('media');
    expect(ids('', 'div', ':hover')).toContain('motion');
    expect(ids('', 'div', ':hover')).not.toContain('transition');
  });

  it('writes a starting value, takes it away on a second click, and clears what no longer fits', () => {
    let css = press('padding: 1rem;', 'display', 'flex-column');
    expect(declarations(css)).toMatchObject({ padding: '1rem', display: 'flex', 'flex-direction': 'column' });
    css = press(css, 'display', 'grid');
    expect(declarations(css).display).toBe('grid');
    expect(declarations(css)['flex-direction'], 'a grid needs no flex-direction').toBeUndefined();
    expect(snippetState(snippet('display', 'grid'), declarations(css))).toBe('on');
    css = press(css, 'grid', 'columns');
    expect(declarations(css)['grid-template-columns']).toBe('repeat(2, minmax(0, 1fr))');
    css = press(css, 'grid', 'columns');
    expect(declarations(css)['grid-template-columns'], 'a second click takes it away').toBeUndefined();
    expect(snippetState(snippet('text', 'size'), declarations('font-size: 2rem;'))).toBe('set');
  });
});
