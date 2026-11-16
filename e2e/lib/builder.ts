import { expect, type Locator, type Page, type Response } from '@playwright/test';

type Tab = 'HTML' | 'CSS' | 'Selettori' | 'Query' | 'Campi' | 'JS' | 'Elemento' | 'Versioni';

/** The builder page (wp-admin/admin.php?action=xb_builder&post=ID) as a user sees it. */
export class Builder {
  private constructor(
    readonly page: Page,
    readonly response: Response | null,
  ) {}

  static url(postId: number): string {
    return `/wp-admin/admin.php?action=xb_builder&post=${postId}`;
  }

  /** Opens the builder and waits until the document is loaded and the canvas shows it. */
  static async open(page: Page, postId: number): Promise<Builder> {
    const response = await page.goto(Builder.url(postId));
    const builder = new Builder(page, response);
    await builder.ready();
    return builder;
  }

  async ready(): Promise<void> {
    await expect(this.status).not.toHaveText('Caricamento…');
    await expect(this.canvasFrame).toHaveAttribute('srcdoc', /xb-live/);
    await expect(this.canvas.locator('body')).not.toBeEmpty();
  }

  get status(): Locator {
    return this.page.locator('header.topbar [role="status"]');
  }

  get canvasFrame(): Locator {
    return this.page.locator('iframe[title="Anteprima della pagina"]');
  }

  get canvas() {
    return this.page.frameLocator('iframe[title="Anteprima della pagina"]');
  }

  get structure(): Locator {
    return this.page.getByRole('navigation', { name: 'Struttura' });
  }

  /** A node of the structure tree, by its label (tag.class, text…). */
  node(label: string): Locator {
    return this.structure.getByRole('button', { name: label, exact: true });
  }

  /** Labels of the structure tree, in document order. */
  async nodes(): Promise<string[]> {
    return this.structure
      .locator('button.name')
      .evaluateAll((buttons) => buttons.map((b) => [...b.childNodes].filter((n) => !(n instanceof Element && (n.classList.contains('icon') || n.classList.contains('auto') || n.classList.contains('text-hint') || n.classList.contains('heading') || n.classList.contains('role') || n.classList.contains('sr')))).map((n) => n.textContent).join('').trim()));
  }

  /** Shows a panel: the code (HTML, CSS, JS) is always in the dock and the element in the side bar; the rest open from the rail. */
  async tab(name: Tab): Promise<void> {
    const section = ({ Selettori: 'Stili e selettori', Query: 'Dati e query', Campi: 'Tipi e campi', Versioni: 'Versioni' } as Partial<Record<Tab, string>>)[name];
    if (!section) {
      // A section open over the canvas would cover it.
      const close = this.page.getByRole('button', { name: "Chiudi e torna all'anteprima" });
      if (await close.isVisible()) await close.click();
      return;
    }
    const button = this.page.getByRole('navigation', { name: 'Sezioni' }).getByRole('button', { name: section, exact: true });
    if ((await button.getAttribute('aria-pressed')) !== 'true') await button.click();
  }

  /** A release in the Versioni panel, by its exact version number. */
  release(version: string): Locator {
    return this.page.locator('.history li').filter({ has: this.page.locator('.head strong').getByText(version, { exact: true }) });
  }

  /** A code editor (CodeMirror) by its accessible label. */
  editor(label: string): Locator {
    return this.page.getByRole('textbox', { name: label, exact: true });
  }

  get html(): Locator {
    return this.editor('Codice HTML');
  }

  /** The text of a code editor, one line per editor line. */
  async source(editor: Locator): Promise<string> {
    return (await editor.locator('.cm-line').allInnerTexts()).join('\n');
  }

  /** Replaces the whole text of a code editor, as typing would (one input event, no auto-closed tags). */
  async write(editor: Locator, text: string): Promise<void> {
    await editor.click();
    await this.page.keyboard.press('ControlOrMeta+a');
    await this.page.keyboard.insertText(text);
  }

  /** Edits the text of a code editor through a transform of its current text. */
  async edit(editor: Locator, change: (text: string) => string): Promise<void> {
    const before = await this.source(editor);
    const after = change(before);
    expect(after, 'la modifica cambia il codice').not.toBe(before);
    await this.write(editor, after);
  }

  get saveButton(): Locator {
    return this.page.locator('header.topbar').getByRole('button', { name: /^(Salva|Salvataggio…)$/ });
  }

  /** Ctrl+S (typing still in a panel's pause is saved too), then waits for the save to reach dev. */
  async save(): Promise<void> {
    await expect(this.saveButton, 'qualcosa da salvare').toBeEnabled();
    await this.page.keyboard.press('ControlOrMeta+s');
    await expect(this.saveButton).toBeDisabled();
    await expect(this.status).toHaveText(/^Salvato/);
  }

  /** REST call with the builder's own nonce (same cookies as the page). */
  async api<T>(method: 'GET' | 'POST', route: string, body?: unknown): Promise<T> {
    const result = await this.page.evaluate(
      async ({ method, route, body }) => {
        const config = (window as unknown as { xbBuilder: { restUrl: string; nonce: string } }).xbBuilder;
        const headers: Record<string, string> = { 'X-WP-Nonce': config.nonce, Accept: 'application/json' };
        if (body !== undefined) headers['Content-Type'] = 'application/json';
        const response = await fetch(config.restUrl + route, { method, headers, credentials: 'same-origin', body: body === undefined ? null : JSON.stringify(body) });
        return { status: response.status, data: await response.json().catch(() => null) };
      },
      { method, route, body },
    );
    if (result.status >= 400) throw new Error(`${method} ${route}: ${result.status} ${JSON.stringify(result.data)}`);
    return result.data as T;
  }

  /** Status of a REST call made outside the page (expected refusals stay out of its console). */
  async apiStatus(method: 'GET' | 'POST', route: string, body?: unknown, nonce = true): Promise<number> {
    const config = await this.page.evaluate(() => (window as unknown as { xbBuilder: { restUrl: string; nonce: string } }).xbBuilder);
    const response = await this.page.request.fetch(config.restUrl + route, {
      method,
      headers: nonce ? { 'X-WP-Nonce': config.nonce } : {},
      ...(body === undefined ? {} : { data: body }),
    });
    return response.status();
  }

  /**
   * Publishes everything saved in dev as a new release without the dialog (setup of later steps), then reloads
   * the builder as the dialog does: publishing moves the heads the open document would otherwise save against.
   */
  async publishAll(notes: string): Promise<{ id: number; version: string }> {
    const changes = await this.api<Array<{ doc: string }>>('GET', 'changes');
    expect(changes.length, 'modifiche da pubblicare').toBeGreaterThan(0);
    const { next } = await this.api<{ next: string }>('GET', 'releases');
    const release = await this.api<{ id: number; version: string }>('POST', 'releases', { version: next, notes, docs: changes.map((c) => c.doc) });
    await this.page.reload();
    await this.ready();
    return release;
  }
}
