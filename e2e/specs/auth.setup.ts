import { expect, test as setup } from '@playwright/test';
import { AUTH_FILE } from '../lib/paths.ts';

// Logs in once through the WordPress login form and keeps the session cookies for every spec.
setup('accesso come amministratore di test', async ({ page }) => {
  const user = process.env.XB_E2E_USER;
  const password = process.env.XB_E2E_PASSWORD;
  if (!user || !password) throw new Error('XB_E2E_USER / XB_E2E_PASSWORD missing: run the suite with dev/e2e.sh');
  await page.goto('/wp-login.php');
  // wp-login.php focuses and empties the username field shortly after load: fill only after that.
  await expect(page.locator('#user_login')).toBeFocused();
  await page.waitForTimeout(300);
  await page.locator('#user_login').fill(user);
  await page.locator('#user_pass').fill(password);
  await expect(page.locator('#user_login')).toHaveValue(user);
  await page.locator('#wp-submit').click();
  await expect(page).toHaveURL(/wp-admin/);
  // The builder's first-run tour (list 142) is tested on its own: every other spec starts as one who has seen it.
  await page.evaluate(() => localStorage.setItem('xb-tour-done', '1'));
  await page.context().storageState({ path: AUTH_FILE });
});
