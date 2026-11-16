import path from 'node:path';

/** Session cookies of the test admin, written by specs/auth.setup.ts (git-ignored). */
export const AUTH_FILE = path.join(import.meta.dirname, '../.auth/admin.json');
