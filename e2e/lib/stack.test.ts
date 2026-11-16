import { describe, expect, it } from 'vitest';
import { e2eStack } from './stack.ts';

describe('e2eStack', () => {
  it('refuses to run without a compose project', () => {
    expect(() => e2eStack({ XB_E2E_URL: 'http://localhost:8898' })).toThrow(/COMPOSE_PROJECT_NAME not set/);
  });

  it('refuses to run without a URL', () => {
    expect(() => e2eStack({ COMPOSE_PROJECT_NAME: 'xb-mio' })).toThrow(/XB_E2E_URL not set/);
  });

  it('does not take blank values', () => {
    expect(() => e2eStack({ COMPOSE_PROJECT_NAME: ' ', XB_E2E_URL: '' })).toThrow(/COMPOSE_PROJECT_NAME and XB_E2E_URL/);
  });

  it('returns the stack it was given', () => {
    expect(e2eStack({ COMPOSE_PROJECT_NAME: 'xb-mio', XB_E2E_URL: 'http://localhost:8901' })).toEqual({ project: 'xb-mio', url: 'http://localhost:8901' });
  });
});
