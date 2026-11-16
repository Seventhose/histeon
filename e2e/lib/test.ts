import { test as base, expect } from '@playwright/test';
import { fixtures, type Fixtures } from './fixtures.ts';

/** What Chromium logs when a script is refused in the canvas: the trace recorder's own snapshot scripts do it. */
export const SANDBOX_BLOCKED = /^Blocked script execution in 'about:(srcdoc|blank)' because the document's frame is sandboxed/;

/**
 * wp-admin (6.9+) opts in to cross-document view transitions; navigating from it to a page that does not, such as
 * the builder, makes Chromium reject the incoming transition (an unhandled DOMException on the new page). Browser
 * noise, not a failure of either page.
 */
const IGNORED = [/^Transition was aborted because of invalid state\. ViewTransition opt-in disabled$/];

/**
 * Every test fails on a console error or an uncaught exception in any page of its context (builder, preview
 * tabs, wp-admin): CSP violations and broken scripts show up there first. While a trace is recorded, the
 * recorder's refused snapshot scripts in the sandboxed canvas are expected; security.spec.ts runs without a
 * trace and checks that nothing else ever tries to run a script there.
 */
export const test = base.extend<{ consoleErrors: string[]; data: Fixtures }>({
  consoleErrors: [
    async ({ context, trace }, use) => {
      const tracing = (typeof trace === 'string' ? trace : trace.mode) !== 'off';
      const errors: string[] = [];
      context.on('console', (message) => {
        if (message.type() !== 'error' || IGNORED.some((re) => re.test(message.text()))) return;
        if (tracing && SANDBOX_BLOCKED.test(message.text())) return;
        errors.push(`${message.page()?.url() ?? '?'}: ${message.text()}`);
      });
      context.on('weberror', (error) => {
        if (!IGNORED.some((re) => re.test(error.error().message))) errors.push(`${error.page()?.url() ?? '?'}: ${error.error().message}`);
      });
      await use(errors);
      expect(errors, 'errori nella console').toEqual([]);
    },
    { auto: true },
  ],
  data: async ({}, use) => {
    await use(fixtures());
  },
});

export { expect };
