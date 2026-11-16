/**
 * The compose project and URL of the stack the tests run against. There is no default: several sessions run
 * their own stacks side by side, and a fallback to the usual `xb-e2e` once made a stray `playwright test`
 * recreate another session's database container. dev/e2e.sh sets both.
 */
export function e2eStack(env: NodeJS.ProcessEnv = process.env): { project: string; url: string } {
  const project = env.COMPOSE_PROJECT_NAME?.trim();
  const url = env.XB_E2E_URL?.trim();
  const missing = [!project && 'COMPOSE_PROJECT_NAME', !url && 'XB_E2E_URL'].filter(Boolean);
  if (missing.length) {
    throw new Error(
      `${missing.join(' and ')} not set: run the tests through dev/e2e.sh (XB_E2E_PROJECT and XB_E2E_PORT pick your own stack), ` +
        'or set both for a stack you started yourself. There is no default, so another session\'s stack is never touched.',
    );
  }
  return { project: project!, url: url! };
}
