import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { mkdtemp, rm } from 'node:fs/promises';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';
import { setTimeout as delay } from 'node:timers/promises';
import test from 'node:test';

let server;
let dataDir;
let baseUrl;
let cookie;

async function availablePort() {
  const probe = net.createServer();
  probe.listen(0, '127.0.0.1');
  await once(probe, 'listening');
  const { port } = probe.address();
  await new Promise((resolve, reject) => probe.close((error) => error ? reject(error) : resolve()));
  return port;
}

async function request(pathname, options = {}) {
  const response = await fetch(`${baseUrl}${pathname}`, {
    redirect: 'manual',
    ...options,
    headers: { ...(cookie ? { Cookie: cookie } : {}), ...options.headers },
  });
  const setCookie = response.headers.get('set-cookie');
  if (setCookie) cookie = setCookie.split(';', 1)[0];
  return response;
}

function tokenFrom(html) {
  const token = html.match(/name="_csrf" value="([^"]+)"/);
  assert.ok(token, 'page includes a CSRF token');
  return token[1];
}

test('setup, authenticated registry actions, admin, and CSRF', async (t) => {
  const port = await availablePort();
  dataDir = await mkdtemp(path.join(os.tmpdir(), 'giftreg-smoke-'));
  baseUrl = `http://127.0.0.1:${port}`;
  server = spawn(process.execPath, ['server.js'], {
    cwd: path.resolve(import.meta.dirname, '..'),
    env: { ...process.env, DATA_DIR: dataDir, PORT: String(port), SESSION_SECRET: 'test-session-secret-long-enough' },
    stdio: ['ignore', 'ignore', 'pipe'],
  });
  let stderr = '';
  server.stderr.setEncoding('utf8');
  server.stderr.on('data', (chunk) => { stderr += chunk; });
  t.after(async () => {
    server.kill();
    await Promise.race([once(server, 'exit'), delay(3000)]);
    await rm(dataDir, { recursive: true, force: true });
  });

  let ready = false;
  for (let attempt = 0; attempt < 60; attempt += 1) {
    if (server.exitCode !== null) assert.fail(`server exited during startup: ${stderr}`);
    try {
      const response = await fetch(`${baseUrl}/health`);
      if (response.ok) { ready = true; break; }
    } catch {}
    await delay(100);
  }
  assert.ok(ready, `server became ready: ${stderr}`);

  const setupPage = await request('/setup');
  assert.equal(setupPage.status, 200);
  const setupToken = tokenFrom(await setupPage.text());
  const setup = await request('/setup', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ _csrf: setupToken, familyname: 'Smoke Family', fullname: 'Test Admin', email: 'admin@example.test', username: 'admin', password: 'strong-test-password-123' }),
  });
  assert.equal(setup.status, 302);
  assert.equal(setup.headers.get('location'), '/');

  const dashboard = await request('/');
  assert.equal(dashboard.status, 200);
  const dashboardHtml = await dashboard.text();
  assert.match(dashboardHtml, /Your ideas/);
  const dashboardToken = tokenFrom(dashboardHtml);

  const badCsrf = await request('/items', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ description: 'Should not be created', price: '5', quantity: '1' }),
  });
  assert.equal(badCsrf.status, 403);

  const itemResponse = await request('/items', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ _csrf: dashboardToken, description: 'A thoughtful book', price: '19.95', source: 'Local shop', quantity: '1' }),
  });
  assert.equal(itemResponse.status, 302);
  const updatedDashboard = await request('/');
  assert.match(await updatedDashboard.text(), /A thoughtful book/);

  const adminPage = await request('/admin');
  assert.equal(adminPage.status, 200);
  assert.match(await adminPage.text(), /Guardianships/);
  const adminCookie = cookie;

  cookie = undefined;
  const signupPage = await request('/signup');
  const signupToken = tokenFrom(await signupPage.text());
  const signup = await request('/signup', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ _csrf: signupToken, username: 'member', fullname: 'Test Member', email: 'member@example.test', family_id: '1', password: 'strong-member-password-123' }),
  });
  assert.equal(signup.status, 302);

  cookie = adminCookie;
  const adminAccounts = await request('/admin');
  const adminToken = tokenFrom(await adminAccounts.text());
  const approved = await request('/admin/users/2', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ _csrf: adminToken, action: 'approve' }),
  });
  assert.equal(approved.status, 302);

  const adminDashboard = await request('/');
  const adminDashboardToken = tokenFrom(await adminDashboard.text());
  await request('/shopping-requests/2', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ _csrf: adminDashboardToken, action: 'request' }),
  });

  cookie = undefined;
  const memberLogin = await request('/login');
  const memberLoginToken = tokenFrom(await memberLogin.text());
  const memberAuth = await request('/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ _csrf: memberLoginToken, username: 'member', password: 'strong-member-password-123' }),
  });
  assert.equal(memberAuth.status, 302);
  const memberDashboard = await request('/');
  const memberToken = tokenFrom(await memberDashboard.text());
  await request('/shopping-requests/1', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ _csrf: memberToken, action: 'approve' }),
  });
  const itemForm = await request('/items/new');
  const itemToken = tokenFrom(await itemForm.text());
  await request('/items', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ _csrf: itemToken, description: 'A reserved gift', price: '12.50', quantity: '1' }),
  });

  cookie = adminCookie;
  const shopPage = await request('/shop/2');
  assert.equal(shopPage.status, 200);
  const shopHtml = await shopPage.text();
  assert.match(shopHtml, /A reserved gift/);
  const shopToken = tokenFrom(shopHtml);
  await request('/allocations', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ _csrf: shopToken, item_id: '2', action: 'reserve', quantity: '1' }),
  });
  const shoppingList = await request('/shopping-list');
  assert.match(await shoppingList.text(), /A reserved gift/);
});
