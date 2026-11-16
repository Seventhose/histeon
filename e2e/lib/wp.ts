import { execFile, execFileSync } from 'node:child_process';
import path from 'node:path';
import { e2eStack } from './stack.ts';

const DEV = path.join(import.meta.dirname, '../../dev');

/**
 * Runs PHP inside the dev WordPress with wp-cli (`wp eval-file -`), with `env` passed to the container
 * by name only, so values such as the test password never appear on a command line.
 */
export function wpEval(php: string, env: Record<string, string>): string {
  const pass = Object.keys(env).flatMap((name) => ['-e', name]);
  return execFileSync('docker', ['compose', 'run', '--rm', '-T', ...pass, 'cli', 'wp', 'eval-file', '-'], {
    cwd: DEV,
    input: php,
    encoding: 'utf8',
    env: { ...process.env, COMPOSE_PROJECT_NAME: e2eStack().project, ...env },
    // stderr is compose's progress output; on failure it is part of the thrown error.
    stdio: ['pipe', 'pipe', 'pipe'],
  });
}

/**
 * wpEval without blocking Node: for long PHP (thousands of writes), so that Playwright's timers keep running and a
 * timeout says where the time went. Same container, same env passing by name.
 */
export function wpEvalAsync(php: string, env: Record<string, string> = {}): Promise<string> {
  const pass = Object.keys(env).flatMap((name) => ['-e', name]);
  return new Promise((resolve, reject) => {
    const child = execFile(
      'docker',
      ['compose', 'run', '--rm', '-T', ...pass, 'cli', 'wp', 'eval-file', '-'],
      { cwd: DEV, encoding: 'utf8', env: { ...process.env, COMPOSE_PROJECT_NAME: e2eStack().project, ...env }, maxBuffer: 16 * 1024 * 1024 },
      (error, stdout, stderr) => (error ? reject(Object.assign(error, { stderr })) : resolve(stdout)),
    );
    child.stdin?.end(php);
  });
}
