/**
 * XB static site Worker (GPL-3.0-or-later).
 *
 * Serves the static assets; POST /xb-form is the only dynamic route:
 *   1. size limit, POST only;
 *   2. Cloudflare Turnstile verified server-side (secret TURNSTILE_SECRET);
 *   3. fields forwarded to WordPress (XB_FORMS_ENDPOINT) as JSON signed with HMAC-SHA256 (secret XB_FORMS_SECRET),
 *      with a timestamp the WordPress side checks against replays.
 * No secret is ever part of the static files.
 *
 * Static files get the site's security headers from the assets config (`_headers`); the Worker adds the same ones
 * (XB_SECURITY_HEADERS, a closed list chosen by WordPress) to what it answers itself.
 */

const MAX_BODY = 32 * 1024;
const MAX_FIELDS = 50;
const MAX_VALUE = 5000;

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    if (url.pathname === '/xb-form') return secured(await handleForm(request, env, url), env);
    return env.ASSETS.fetch(request);
  },
};

const HEADER_NAMES = ['X-Content-Type-Options', 'X-Frame-Options', 'Referrer-Policy', 'Permissions-Policy', 'Content-Security-Policy'];

/** The response with the security headers WordPress sent (only known names, one-line values). */
export function secured(response, env) {
  let wanted = {};
  try {
    wanted = JSON.parse(env.XB_SECURITY_HEADERS || '{}');
  } catch {
    wanted = {};
  }
  const out = new Response(response.body, response);
  for (const name of HEADER_NAMES) {
    const value = wanted && typeof wanted[name] === 'string' ? wanted[name] : '';
    if (value && /^[\x20-\x7e]{1,1000}$/.test(value)) out.headers.set(name, value);
  }
  return out;
}

export async function handleForm(request, env, url = new URL(request.url)) {
  if (request.method !== 'POST') {
    return new Response('Method Not Allowed', { status: 405, headers: { Allow: 'POST' } });
  }
  if (!env.TURNSTILE_SECRET || !env.XB_FORMS_SECRET || !env.XB_FORMS_ENDPOINT) {
    return new Response('Form non configurato', { status: 503 });
  }
  const declared = Number(request.headers.get('content-length') || '0');
  if (declared > MAX_BODY) return new Response('Payload Too Large', { status: 413 });
  // Read with a byte cap: a chunked body without Content-Length is never buffered beyond MAX_BODY.
  const raw = await readCapped(request, MAX_BODY);
  if (raw === null) return new Response('Payload Too Large', { status: 413 });

  let form;
  try {
    form = await new Request(url, { method: 'POST', headers: request.headers, body: raw }).formData();
  } catch {
    return new Response('Bad Request', { status: 400 });
  }

  const token = form.get('cf-turnstile-response');
  if (typeof token !== 'string' || token === '') return new Response('Forbidden', { status: 403 });
  const check = await fetch('https://challenges.cloudflare.com/turnstile/v0/siteverify', {
    method: 'POST',
    body: new URLSearchParams({
      secret: env.TURNSTILE_SECRET,
      response: token,
      remoteip: request.headers.get('CF-Connecting-IP') || '',
      idempotency_key: crypto.randomUUID(),
    }),
  });
  const outcome = await check.json().catch(() => ({ success: false }));
  // The token must have been issued for this site, not replayed from another one.
  if (!outcome.success || outcome.hostname !== url.hostname) {
    return new Response('Forbidden', { status: 403 });
  }

  const fields = {};
  let count = 0;
  for (const [name, value] of form) {
    if (typeof value !== 'string' || name === 'cf-turnstile-response' || name.startsWith('xb_')) continue;
    if (!/^[A-Za-z0-9_-]{1,64}$/.test(name) || ++count > MAX_FIELDS) continue;
    fields[name] = value.slice(0, MAX_VALUE);
  }
  const formName = String(form.get('xb_form') || 'contatti').slice(0, 64);
  const body = JSON.stringify({ form: formName, fields, ts: Math.floor(Date.now() / 1000), id: crypto.randomUUID() });
  const signature = await hmac(env.XB_FORMS_SECRET, body);
  const forwarded = await fetch(env.XB_FORMS_ENDPOINT, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-XB-Signature': signature },
    body,
  });
  if (!forwarded.ok) return new Response('Invio non riuscito', { status: 502 });

  const back = form.get('xb_redirect');
  // Only same-site paths: never an open redirect.
  if (typeof back === 'string' && /^\/(?!\/)[^\s\\]*$/.test(back)) {
    return Response.redirect(new URL(back, url).toString(), 303);
  }
  return new Response('Grazie, messaggio inviato.', { status: 200, headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
}

async function readCapped(request, limit) {
  if (!request.body) return new ArrayBuffer(0);
  const reader = request.body.getReader();
  const chunks = [];
  let total = 0;
  for (;;) {
    const { done, value } = await reader.read();
    if (done) break;
    total += value.byteLength;
    if (total > limit) {
      await reader.cancel();
      return null;
    }
    chunks.push(value);
  }
  const out = new Uint8Array(total);
  let offset = 0;
  for (const chunk of chunks) {
    out.set(chunk, offset);
    offset += chunk.byteLength;
  }
  return out.buffer;
}

export async function hmac(secret, message) {
  const key = await crypto.subtle.importKey('raw', new TextEncoder().encode(secret), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
  const mac = await crypto.subtle.sign('HMAC', key, new TextEncoder().encode(message));
  return [...new Uint8Array(mac)].map((b) => b.toString(16).padStart(2, '0')).join('');
}
