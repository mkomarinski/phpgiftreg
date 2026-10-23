import express from 'express';
import session from 'express-session';
import connectSqlite3 from 'connect-sqlite3';
import bcrypt from 'bcryptjs';
import nodemailer from 'nodemailer';
import * as oidc from 'openid-client';
import crypto from 'node:crypto';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { db, dataDir, getSettings, setSettings } from './db.js';

const app = express();
const root = path.dirname(fileURLToPath(import.meta.url));
const SQLiteStore = connectSqlite3(session);
const port = Number(process.env.PORT || 3000);
const oidcClients = new Map();
if (process.env.NODE_ENV === 'production' && !process.env.SESSION_SECRET) throw new Error('SESSION_SECRET must be set in production.');

app.set('view engine', 'ejs');
app.set('views', path.join(root, 'views'));
app.set('trust proxy', process.env.TRUST_PROXY === '1');
app.disable('x-powered-by');
app.use(express.urlencoded({ extended: false, limit: '32kb' }));
app.use('/assets', express.static(path.join(root, 'public'), { maxAge: '1d' }));
app.use(session({
  store: new SQLiteStore({ db: 'sessions.sqlite', dir: dataDir, concurrentDB: true }),
  secret: process.env.SESSION_SECRET || 'development-only-change-this-session-secret',
  resave: false,
  saveUninitialized: false,
  cookie: { httpOnly: true, sameSite: 'lax', secure: 'auto', maxAge: 86400000 },
}));

const statement = (sql) => db.prepare(sql);
const rows = (sql, ...params) => statement(sql).all(...params);
const row = (sql, ...params) => statement(sql).get(...params);
const run = (sql, ...params) => statement(sql).run(...params);
const id = (value) => Number.parseInt(value, 10);
const toArray = (value) => Array.isArray(value) ? value : value ? [value] : [];
const text = (value, max = 255) => String(value ?? '').trim().replace(/[\u0000-\u001f\u007f]/g, '').slice(0, max);
const enabled = (key, settings = getSettings()) => settings[key] === '1';
const currentUser = (req) => req.session.userId ? row('SELECT id, username, fullname, email, approved, admin, comment, email_msgs FROM users WHERE id = ?', req.session.userId) : null;

function csrfToken(req) {
  if (!req.session.csrf) req.session.csrf = crypto.randomBytes(32).toString('hex');
  return req.session.csrf;
}
function flash(req, type, message) { req.session.flash = { type, message }; }
function redirect(req, res, url, message, type = 'success') {
  if (message) flash(req, type, message);
  res.redirect(url);
}
function render(req, res, view, title, page = {}) {
  const notice = req.session.flash;
  delete req.session.flash;
  res.render('layout', { view, title, page, user: currentUser(req), csrf: csrfToken(req), notice, settings: getSettings() });
}
function requireLogin(req, res, next) {
  if (currentUser(req)) return next();
  res.redirect('/login');
}
function requireAdmin(req, res, next) {
  const user = currentUser(req);
  if (!user) return res.redirect('/login');
  if (!user.admin) return res.status(403).render('layout', { view: 'error', title: 'Not allowed', page: { message: 'Administrator access is required.' }, user, csrf: csrfToken(req), notice: null, settings: getSettings() });
  next();
}

app.use((req, res, next) => {
  if (req.method !== 'POST') return next();
  const token = req.body?._csrf;
  const expected = req.session.csrf;
  if (typeof token !== 'string' || typeof expected !== 'string' || token.length !== expected.length || !crypto.timingSafeEqual(Buffer.from(token), Buffer.from(expected))) {
    return res.status(403).send('Form expired. Return to the previous page and try again.');
  }
  next();
});

function emailTransport() {
  const settings = getSettings();
  if (!settings.smtp_host || !settings.email_from) return null;
  return nodemailer.createTransport({
    host: settings.smtp_host,
    port: Number(settings.smtp_port) || 587,
    secure: settings.smtp_secure === '1',
    auth: settings.smtp_username ? { user: settings.smtp_username, pass: settings.smtp_password } : undefined,
  });
}
function sendEmail(to, subject, body) {
  const transport = emailTransport();
  if (!transport || !to) return;
  transport.sendMail({ from: getSettings().email_from, to, subject, text: body }).catch((error) => console.error('Email delivery failed:', error.message));
}
function sendMessage(senderId, recipientId, message) {
  run('INSERT INTO messages (sender_id, recipient_id, message) VALUES (?, ?, ?)', senderId, recipientId, text(message, 2000));
  const recipient = row('SELECT email, email_msgs FROM users WHERE id = ?', recipientId);
  const sender = row('SELECT fullname, email FROM users WHERE id = ?', senderId);
  if (recipient?.email && recipient.email_msgs) sendEmail(recipient.email, `Gift Registry message from ${sender.fullname}`, `${sender.fullname} sends:\n\n${message}`);
}
function currentUserById(userId) { return row('SELECT id, fullname FROM users WHERE id = ?', userId); }
function notifySubscribers(userId, action) {
  const settings = getSettings();
  const cutoff = Date.now() - Number(settings.notify_threshold_minutes || 60) * 60000;
  for (const subscriber of rows('SELECT subscriber_id, last_notified FROM subscriptions WHERE publisher_id = ?', userId)) {
    if (subscriber.last_notified && new Date(`${subscriber.last_notified.replace(' ', 'T')}Z`).getTime() > cutoff) continue;
    sendMessage(userId, subscriber.subscriber_id, `${currentUserById(userId).fullname} ${action}`);
    run('UPDATE subscriptions SET last_notified = CURRENT_TIMESTAMP WHERE publisher_id = ? AND subscriber_id = ?', userId, subscriber.subscriber_id);
  }
}
function canShop(shopperId, ownerId) {
  return Boolean(row('SELECT 1 FROM shoppers WHERE shopper_id = ? AND owner_id = ? AND pending = 0', shopperId, ownerId));
}
function listEventsFor(userId, settings = getSettings()) {
  const events = rows(`SELECT e.*, u.fullname FROM events e LEFT JOIN users u ON u.id = e.user_id
    WHERE e.user_id IS NULL OR e.user_id = ? OR EXISTS (
      SELECT 1 FROM shoppers s WHERE s.shopper_id = ? AND s.owner_id = e.user_id AND s.pending = 0
    ) ORDER BY e.event_date`, userId, userId);
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  return events.map((event) => {
    let date = new Date(`${event.event_date}T00:00:00`);
    if (event.recurring) {
      date = new Date(today.getFullYear(), date.getMonth(), date.getDate());
      if (date < today) date.setFullYear(date.getFullYear() + 1);
    }
    const days = Math.round((date - today) / 86400000);
    return { ...event, nextDate: date.toISOString().slice(0, 10), days };
  }).filter((event) => event.days >= 0 && event.days <= Number(settings.event_threshold || 30) && (settings.show_own_events === '1' || event.user_id !== userId));
}
function oidcConfig(settings) {
  const issuer = text(settings.oidc_issuer, 500).replace(/\/$/, '');
  if (!issuer || !settings.oidc_client_id) throw new Error('Complete the OIDC issuer and client ID in Admin settings first.');
  const key = `${issuer}|${settings.oidc_client_id}`;
  if (!oidcClients.has(key)) oidcClients.set(key, oidc.discovery(new URL(issuer), settings.oidc_client_id, settings.oidc_client_secret || undefined));
  return oidcClients.get(key);
}
function callbackUri(req, settings) {
  return process.env.OIDC_REDIRECT_URI || settings.oidc_redirect_uri || `${req.protocol}://${req.get('host')}/auth/oidc/callback`;
}

app.get('/setup', (req, res) => row('SELECT 1 FROM users LIMIT 1') ? res.redirect('/login') : render(req, res, 'setup', 'Create your registry'));
app.post('/setup', async (req, res, next) => {
  try {
    if (row('SELECT 1 FROM users LIMIT 1')) return res.redirect('/login');
    const username = text(req.body.username, 80);
    const fullname = text(req.body.fullname, 100);
    const email = text(req.body.email, 255).toLowerCase();
    const familyName = text(req.body.familyname, 100);
    const password = String(req.body.password || '');
    if (!username || !fullname || !familyName || password.length < 12 || !/^\S+@\S+\.\S+$/.test(email)) {
      flash(req, 'error', 'Enter a username, full name, valid email, family name, and password of at least 12 characters.');
      return res.redirect('/setup');
    }
    const create = db.transaction(() => {
      const family = run('INSERT INTO families (name) VALUES (?)', familyName);
      const user = run('INSERT INTO users (username, password_hash, fullname, email, approved, admin) VALUES (?, ?, ?, ?, 1, 1)', username, bcrypt.hashSync(password, 12), fullname, email);
      run('INSERT INTO memberships (user_id, family_id) VALUES (?, ?)', user.lastInsertRowid, family.lastInsertRowid);
      return Number(user.lastInsertRowid);
    });
    const userId = create();
    await new Promise((resolve, reject) => req.session.regenerate((error) => error ? reject(error) : resolve()));
    req.session.userId = userId;
    res.redirect('/');
  } catch (error) { next(error); }
});

app.get('/login', (req, res) => render(req, res, 'login', 'Sign in'));
app.post('/login', async (req, res, next) => {
  try {
    const username = text(req.body.username, 80);
    const password = String(req.body.password || '');
    const user = row('SELECT * FROM users WHERE username = ? COLLATE NOCASE', username);
    if (!user || !user.approved || !await bcrypt.compare(password, user.password_hash)) {
      flash(req, 'error', user && !user.approved ? 'Your account is awaiting administrator approval.' : 'Username or password was not recognized.');
      return res.redirect('/login');
    }
    await new Promise((resolve, reject) => req.session.regenerate((error) => error ? reject(error) : resolve()));
    req.session.userId = user.id;
    res.redirect('/');
  } catch (error) { next(error); }
});
app.post('/logout', (req, res) => req.session.destroy(() => res.redirect('/login')));

app.get('/signup', (req, res) => render(req, res, 'signup', 'Request an account', { families: rows('SELECT * FROM families ORDER BY name') }));
app.post('/signup', async (req, res, next) => {
  try {
    const username = text(req.body.username, 80);
    const fullname = text(req.body.fullname, 100);
    const email = text(req.body.email, 255).toLowerCase();
    const familyId = id(req.body.family_id);
    const password = String(req.body.password || '');
    const settings = getSettings();
    if (!username || !fullname || !/^\S+@\S+\.\S+$/.test(email) || password.length < 12) return redirect(req, res, '/signup', 'Enter a username, full name, valid email, and password of at least 12 characters.', 'error');
    if (row('SELECT 1 FROM users WHERE username = ? COLLATE NOCASE OR email = ? COLLATE NOCASE', username, email)) return redirect(req, res, '/signup', 'That username or email address is already registered.', 'error');
    if (familyId && !row('SELECT 1 FROM families WHERE id = ?', familyId)) return redirect(req, res, '/signup', 'Choose a valid family.', 'error');
    const approved = enabled('newuser_requires_approval', settings) ? 0 : 1;
    const created = run('INSERT INTO users (username, fullname, email, password_hash, approved, initial_family_id) VALUES (?, ?, ?, ?, ?, ?)', username, fullname, email, await bcrypt.hash(password, 12), approved, familyId || null);
    if (approved && familyId) run('INSERT INTO memberships (user_id, family_id) VALUES (?, ?)', created.lastInsertRowid, familyId);
    if (!approved) {
      for (const admin of rows('SELECT id, fullname, email FROM users WHERE admin = 1')) {
        sendMessage(created.lastInsertRowid, admin.id, `${fullname} requested a Gift Registry account. Review this request in Admin.`);
        sendEmail(admin.email, 'Gift Registry account request', `${fullname} <${email}> requested an account.`);
      }
    } else sendEmail(email, 'Gift Registry account created', 'Your account is ready. Sign in with the password you chose.');
    redirect(req, res, '/login', approved ? 'Account created. You can now sign in.' : 'Account request submitted. An administrator will review it.');
  } catch (error) { next(error); }
});

app.get('/auth/oidc/login', async (req, res, next) => {
  try {
    const settings = getSettings();
    if (!enabled('oidc_enabled', settings)) return redirect(req, res, '/login', 'Single sign-on is not enabled.', 'error');
    const config = await oidcConfig(settings);
    const state = oidc.randomState();
    const nonce = oidc.randomNonce();
    const verifier = oidc.randomPKCECodeVerifier();
    const challenge = await oidc.calculatePKCECodeChallenge(verifier);
    req.session.oidc = { state, nonce, verifier };
    const params = { redirect_uri: callbackUri(req, settings), scope: settings.oidc_scopes || 'openid email profile', state, nonce, code_challenge: challenge, code_challenge_method: 'S256' };
    if (settings.oidc_prompt) params.prompt = settings.oidc_prompt;
    res.redirect(oidc.buildAuthorizationUrl(config, params).href);
  } catch (error) { next(error); }
});
app.get('/auth/oidc/callback', async (req, res, next) => {
  try {
    const settings = getSettings();
    const pending = req.session.oidc;
    delete req.session.oidc;
    if (!pending || !enabled('oidc_enabled', settings)) return redirect(req, res, '/login', 'The sign-in request expired. Start again.', 'error');
    const config = await oidcConfig(settings);
    const redirectUrl = callbackUri(req, settings);
    const tokens = await oidc.authorizationCodeGrant(config, new URL(req.originalUrl, redirectUrl), { pkceCodeVerifier: pending.verifier, expectedState: pending.state, expectedNonce: pending.nonce, idTokenExpected: true });
    const claims = tokens.claims();
    if (!claims?.sub) return redirect(req, res, '/login', 'The identity provider returned no subject.', 'error');
    const issuer = settings.oidc_issuer.replace(/\/$/, '');
    let user = row('SELECT u.* FROM oidc_identities i JOIN users u ON u.id = i.user_id WHERE i.issuer = ? AND i.subject = ?', issuer, claims.sub);
    if (!user && claims.email && claims.email_verified === true) {
      user = row('SELECT * FROM users WHERE email = ? COLLATE NOCASE', claims.email);
      if (user) run('INSERT OR IGNORE INTO oidc_identities (issuer, subject, user_id) VALUES (?, ?, ?)', issuer, claims.sub, user.id);
    }
    if (!user && enabled('oidc_auto_provision', settings) && claims.email && claims.email_verified === true) {
      const prefix = text(claims.preferred_username || claims.email.split('@')[0], 70).replace(/[^a-zA-Z0-9_.@-]/g, '') || `oidc-${crypto.randomBytes(4).toString('hex')}`;
      let username = prefix;
      for (let suffix = 1; row('SELECT 1 FROM users WHERE username = ? COLLATE NOCASE', username); suffix += 1) username = `${prefix.slice(0, 70)}${suffix}`;
      const created = run('INSERT INTO users (username, fullname, email, password_hash, approved) VALUES (?, ?, ?, ?, ?)', username, text(claims.name || claims.email, 100), claims.email, await bcrypt.hash(crypto.randomBytes(32).toString('hex'), 12), enabled('oidc_auto_approve', settings) ? 1 : 0);
      user = row('SELECT * FROM users WHERE id = ?', created.lastInsertRowid);
      run('INSERT INTO oidc_identities (issuer, subject, user_id) VALUES (?, ?, ?)', issuer, claims.sub, user.id);
      if (!user.approved) for (const admin of rows('SELECT email FROM users WHERE admin = 1')) sendEmail(admin.email, 'Gift Registry account request', `${user.fullname} <${user.email}> signed in with OIDC and needs approval.`);
    }
    if (!user) return redirect(req, res, '/login', 'No account is linked to this verified identity. Ask an administrator to enable provisioning or add your account.', 'error');
    if (!user.approved) return redirect(req, res, '/login', 'Your account is awaiting administrator approval.', 'error');
    await new Promise((resolve, reject) => req.session.regenerate((error) => error ? reject(error) : resolve()));
    req.session.userId = user.id;
    res.redirect('/');
  } catch (error) { next(error); }
});

app.get('/', requireLogin, (req, res) => {
  const user = currentUser(req);
  const settings = getSettings();
  const familyPeople = rows(`SELECT DISTINCT u.id, u.fullname, u.comment, s.pending,
      EXISTS(SELECT 1 FROM shoppers allowed WHERE allowed.shopper_id = ? AND allowed.owner_id = u.id AND allowed.pending = 0) AS can_shop
    FROM memberships mine JOIN memberships theirs ON mine.family_id = theirs.family_id
    JOIN users u ON u.id = theirs.user_id LEFT JOIN shoppers s ON s.shopper_id = ? AND s.owner_id = u.id
    WHERE mine.user_id = ? AND u.id <> ? AND u.approved = 1 ORDER BY u.fullname`, user.id, user.id, user.id, user.id);
  const incoming = rows('SELECT s.shopper_id, u.fullname FROM shoppers s JOIN users u ON u.id = s.shopper_id WHERE s.owner_id = ? AND s.pending = 1 ORDER BY u.fullname', user.id);
  const unread = rows('SELECT m.*, u.fullname AS sender_name FROM messages m JOIN users u ON u.id = m.sender_id WHERE m.recipient_id = ? AND m.is_read = 0 ORDER BY m.created_at DESC', user.id);
  const items = rows(`SELECT i.*, c.name AS category, r.title AS rank_title, r.rank_order FROM items i
    LEFT JOIN categories c ON c.id = i.category_id LEFT JOIN ranks r ON r.id = i.rank_id
    WHERE i.user_id = ? ORDER BY r.rank_order DESC, i.description`, user.id);
  const events = listEventsFor(user.id, settings);
  const subscribed = new Set(rows('SELECT publisher_id FROM subscriptions WHERE subscriber_id = ?', user.id).map((item) => item.publisher_id));
  render(req, res, 'home', 'Your registry', { familyPeople, incoming, unread, items, events, subscribed, settings });
});

app.get('/items', requireLogin, (req, res) => res.redirect('/'));
app.get('/items/new', requireLogin, (req, res) => render(req, res, 'item-form', 'Add a gift idea', { item: {}, categories: rows('SELECT * FROM categories ORDER BY name'), ranks: rows('SELECT * FROM ranks ORDER BY rank_order') }));
app.get('/items/:id/edit', requireLogin, (req, res) => {
  const item = row('SELECT * FROM items WHERE id = ? AND user_id = ?', id(req.params.id), currentUser(req).id);
  if (!item) return res.sendStatus(404);
  render(req, res, 'item-form', 'Edit a gift idea', { item, categories: rows('SELECT * FROM categories ORDER BY name'), ranks: rows('SELECT * FROM ranks ORDER BY rank_order') });
});
function validateItem(body) {
  const description = text(body.description, 255);
  const price = Number(String(body.price || '0').replaceAll(',', ''));
  const quantity = enabled('allow_multiples') ? id(body.quantity) : 1;
  const url = text(body.url, 1000);
  if (!description || !Number.isFinite(price) || price < 0 || !Number.isInteger(quantity) || quantity < 1) throw new Error('Enter a description, non-negative price, and quantity of at least one.');
  if (url && !/^https?:\/\//i.test(url)) throw new Error('Item links must start with http:// or https://.');
  return { description, price, quantity, url: url || null, source: text(body.source, 255), categoryId: id(body.category_id) || null, rankId: id(body.rank_id) || null, comment: text(body.comment, 2000) };
}
app.post('/items', requireLogin, (req, res) => {
  try {
    const item = validateItem(req.body);
    run('INSERT INTO items (user_id, description, price, source, url, category_id, rank_id, comment, quantity) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', currentUser(req).id, item.description, item.price, item.source, item.url, item.categoryId, item.rankId, item.comment, item.quantity);
    notifySubscribers(currentUser(req).id, `has added "${item.description}" to their list.`);
    redirect(req, res, '/', 'Gift idea added.');
  } catch (error) { redirect(req, res, '/items/new', error.message, 'error'); }
});
app.post('/items/:id', requireLogin, (req, res) => {
  const itemId = id(req.params.id);
  if (!row('SELECT 1 FROM items WHERE id = ? AND user_id = ?', itemId, currentUser(req).id)) return res.sendStatus(404);
  try {
    const item = validateItem(req.body);
    run('UPDATE items SET description = ?, price = ?, source = ?, url = ?, category_id = ?, rank_id = ?, comment = ?, quantity = ? WHERE id = ? AND user_id = ?', item.description, item.price, item.source, item.url, item.categoryId, item.rankId, item.comment, item.quantity, itemId, currentUser(req).id);
    notifySubscribers(currentUser(req).id, `has updated "${item.description}" on their list.`);
    redirect(req, res, '/', 'Gift idea updated.');
  } catch (error) { redirect(req, res, `/items/${itemId}/edit`, error.message, 'error'); }
});
app.post('/items/:id/delete', requireLogin, (req, res) => {
  const user = currentUser(req);
  const item = row('SELECT * FROM items WHERE id = ? AND user_id = ?', id(req.params.id), user.id);
  if (!item) return res.sendStatus(404);
  for (const allocation of rows('SELECT user_id, bought, quantity FROM allocations WHERE item_id = ?', item.id)) {
    sendMessage(user.id, allocation.user_id, `${item.description} that you ${allocation.bought ? 'bought' : 'reserved'} (${allocation.quantity}) for ${user.fullname} was deleted. Please check whether it is still needed.`);
  }
  run('DELETE FROM items WHERE id = ?', item.id);
  notifySubscribers(user.id, `has deleted "${item.description}" from their list.`);
  redirect(req, res, '/', 'Gift idea deleted.');
});

app.post('/shopping-requests/:ownerId', requireLogin, (req, res) => {
  const user = currentUser(req);
  const ownerId = id(req.params.ownerId);
  if (ownerId === user.id || !row('SELECT 1 FROM users WHERE id = ? AND approved = 1', ownerId)) return res.sendStatus(400);
  const action = req.body.action;
  if (action === 'request') {
    if (!row('SELECT 1 FROM memberships mine JOIN memberships theirs ON mine.family_id = theirs.family_id WHERE mine.user_id = ? AND theirs.user_id = ?', user.id, ownerId)) return res.sendStatus(403);
    const pending = enabled('shop_requires_approval') ? 1 : 0;
    run('INSERT INTO shoppers (shopper_id, owner_id, pending) VALUES (?, ?, ?) ON CONFLICT(shopper_id, owner_id) DO UPDATE SET pending = excluded.pending', user.id, ownerId, pending);
    if (pending) sendMessage(user.id, ownerId, `${user.fullname} requested permission to shop for you.`);
    return redirect(req, res, '/', pending ? 'Shopping request sent.' : 'You can now shop for this family member.');
  }
  if (action === 'cancel') {
    run('DELETE FROM shoppers WHERE shopper_id = ? AND owner_id = ?', user.id, ownerId);
    run('DELETE FROM subscriptions WHERE publisher_id = ? AND subscriber_id = ?', ownerId, user.id);
    return redirect(req, res, '/', 'Shopping access removed.');
  }
  if (action === 'approve' || action === 'decline') {
    if (!row('SELECT 1 FROM shoppers WHERE shopper_id = ? AND owner_id = ? AND pending = 1', ownerId, user.id)) return res.sendStatus(404);
    if (action === 'approve') {
      run('UPDATE shoppers SET pending = 0 WHERE shopper_id = ? AND owner_id = ?', ownerId, user.id);
      sendMessage(user.id, ownerId, `${user.fullname} approved your request to shop for them.`);
    } else {
      run('DELETE FROM shoppers WHERE shopper_id = ? AND owner_id = ?', ownerId, user.id);
      sendMessage(user.id, ownerId, `${user.fullname} declined your request to shop for them.`);
    }
    return redirect(req, res, '/', action === 'approve' ? 'Request approved.' : 'Request declined.');
  }
  res.sendStatus(400);
});
app.post('/subscriptions/:ownerId', requireLogin, (req, res) => {
  const user = currentUser(req);
  const ownerId = id(req.params.ownerId);
  if (!canShop(user.id, ownerId)) return res.sendStatus(403);
  if (req.body.action === 'subscribe') run('INSERT OR IGNORE INTO subscriptions (publisher_id, subscriber_id) VALUES (?, ?)', ownerId, user.id);
  else run('DELETE FROM subscriptions WHERE publisher_id = ? AND subscriber_id = ?', ownerId, user.id);
  redirect(req, res, '/', req.body.action === 'subscribe' ? 'List updates enabled.' : 'List updates disabled.');
});

app.get('/shop/:ownerId', requireLogin, (req, res) => {
  const user = currentUser(req);
  const ownerId = id(req.params.ownerId);
  if (ownerId === user.id || !canShop(user.id, ownerId)) return res.status(403).render('layout', { view: 'error', title: 'List unavailable', page: { message: 'You do not have permission to view this list.' }, user, csrf: csrfToken(req), notice: null, settings: getSettings() });
  const owner = row('SELECT id, fullname, comment FROM users WHERE id = ?', ownerId);
  const settings = getSettings();
  const items = rows(`SELECT i.*, c.name AS category, r.title AS rank_title FROM items i
    LEFT JOIN categories c ON c.id = i.category_id LEFT JOIN ranks r ON r.id = i.rank_id
    WHERE i.user_id = ? ORDER BY r.rank_order DESC, i.description`, ownerId).map((item) => {
      const allocations = rows('SELECT a.user_id, a.bought, a.quantity, u.fullname FROM allocations a JOIN users u ON u.id = a.user_id WHERE a.item_id = ?', item.id);
      const reserved = allocations.filter((allocation) => !allocation.bought).reduce((sum, allocation) => sum + allocation.quantity, 0);
      const bought = allocations.filter((allocation) => allocation.bought).reduce((sum, allocation) => sum + allocation.quantity, 0);
      return { ...item, reserved, bought, available: Math.max(0, item.quantity - reserved - bought), mineReserved: allocations.find((a) => a.user_id === user.id && !a.bought)?.quantity || 0, mineBought: allocations.find((a) => a.user_id === user.id && a.bought)?.quantity || 0, allocationNames: settings.anonymous_purchasing === '1' ? allocations.map((a) => `${a.quantity} ${a.bought ? 'bought' : 'reserved'}`) : allocations.map((a) => `${a.quantity} ${a.bought ? 'bought' : 'reserved'} by ${a.fullname}`) };
    });
  render(req, res, 'shop', `${owner.fullname}'s list`, { owner, items, settings });
});
app.post('/allocations', requireLogin, (req, res) => {
  const user = currentUser(req);
  const item = row('SELECT i.*, u.id AS owner_id FROM items i JOIN users u ON u.id = i.user_id WHERE i.id = ?', id(req.body.item_id));
  if (!item || item.owner_id === user.id || !canShop(user.id, item.owner_id)) return res.sendStatus(403);
  const action = req.body.action;
  const quantity = Math.max(1, Math.min(100, id(req.body.quantity) || 1));
  try {
    db.transaction(() => {
      const reserved = row('SELECT quantity FROM allocations WHERE item_id = ? AND user_id = ? AND bought = 0', item.id, user.id)?.quantity || 0;
      const bought = row('SELECT quantity FROM allocations WHERE item_id = ? AND user_id = ? AND bought = 1', item.id, user.id)?.quantity || 0;
      const total = row('SELECT COALESCE(SUM(quantity), 0) AS allocated FROM allocations WHERE item_id = ?', item.id).allocated;
      if (action === 'reserve') {
        if (total + quantity > item.quantity) throw new Error('That quantity is no longer available.');
        run('INSERT INTO allocations (item_id, user_id, bought, quantity) VALUES (?, ?, 0, ?) ON CONFLICT(item_id, user_id, bought) DO UPDATE SET quantity = quantity + excluded.quantity', item.id, user.id, quantity);
      } else if (action === 'release' && reserved) {
        const left = Math.max(0, reserved - quantity);
        left ? run('UPDATE allocations SET quantity = ? WHERE item_id = ? AND user_id = ? AND bought = 0', left, item.id, user.id) : run('DELETE FROM allocations WHERE item_id = ? AND user_id = ? AND bought = 0', item.id, user.id);
      } else if (action === 'purchase' && reserved) {
        const moved = Math.min(quantity, reserved);
        const left = reserved - moved;
        left ? run('UPDATE allocations SET quantity = ? WHERE item_id = ? AND user_id = ? AND bought = 0', left, item.id, user.id) : run('DELETE FROM allocations WHERE item_id = ? AND user_id = ? AND bought = 0', item.id, user.id);
        run('INSERT INTO allocations (item_id, user_id, bought, quantity) VALUES (?, ?, 1, ?) ON CONFLICT(item_id, user_id, bought) DO UPDATE SET quantity = quantity + excluded.quantity', item.id, user.id, moved);
      } else if (action === 'return' && bought) {
        const moved = Math.min(quantity, bought);
        const left = bought - moved;
        left ? run('UPDATE allocations SET quantity = ? WHERE item_id = ? AND user_id = ? AND bought = 1', left, item.id, user.id) : run('DELETE FROM allocations WHERE item_id = ? AND user_id = ? AND bought = 1', item.id, user.id);
        run('INSERT INTO allocations (item_id, user_id, bought, quantity) VALUES (?, ?, 0, ?) ON CONFLICT(item_id, user_id, bought) DO UPDATE SET quantity = quantity + excluded.quantity', item.id, user.id, moved);
      } else throw new Error('That allocation action is not available.');
    })();
    redirect(req, res, `/shop/${item.owner_id}`, 'Shopping list updated.');
  } catch (error) { redirect(req, res, `/shop/${item.owner_id}`, error.message, 'error'); }
});
app.get('/shopping-list', requireLogin, (req, res) => {
  const items = rows(`SELECT i.description, i.source, i.price, a.quantity, a.bought, u.fullname AS owner_name
    FROM allocations a JOIN items i ON i.id = a.item_id JOIN users u ON u.id = i.user_id
    WHERE a.user_id = ? ORDER BY a.bought, u.fullname, i.description`, currentUser(req).id);
  const total = items.filter((item) => item.bought).reduce((sum, item) => sum + item.price * item.quantity, 0);
  render(req, res, 'shopping-list', 'My shopping list', { items, total });
});
app.post('/messages/:id/read', requireLogin, (req, res) => {
  run('UPDATE messages SET is_read = 1 WHERE id = ? AND recipient_id = ?', id(req.params.id), currentUser(req).id);
  redirect(req, res, '/', 'Message marked as read.');
});

app.get('/profile', requireLogin, (req, res) => render(req, res, 'profile', 'Your profile', { profile: currentUser(req) }));
app.post('/profile', requireLogin, async (req, res, next) => {
  try {
    const user = currentUser(req);
    const fullname = text(req.body.fullname, 100);
    const email = text(req.body.email, 255).toLowerCase();
    const comment = text(req.body.comment, 1000);
    if (!fullname || !/^\S+@\S+\.\S+$/.test(email)) return redirect(req, res, '/profile', 'Enter a full name and valid email address.', 'error');
    if (row('SELECT 1 FROM users WHERE email = ? COLLATE NOCASE AND id <> ?', email, user.id)) return redirect(req, res, '/profile', 'That email address is already in use.', 'error');
    let passwordHash;
    if (req.body.new_password) {
      const account = row('SELECT password_hash FROM users WHERE id = ?', user.id);
      if (!await bcrypt.compare(String(req.body.current_password || ''), account.password_hash)) return redirect(req, res, '/profile', 'Current password was not recognized.', 'error');
      if (String(req.body.new_password).length < 12) return redirect(req, res, '/profile', 'New passwords must be at least 12 characters.', 'error');
      passwordHash = await bcrypt.hash(String(req.body.new_password), 12);
    }
    run(`UPDATE users SET fullname = ?, email = ?, comment = ?, email_msgs = ?${passwordHash ? ', password_hash = ?' : ''} WHERE id = ?`, ...[fullname, email, comment, req.body.email_msgs === '1' ? 1 : 0, ...(passwordHash ? [passwordHash] : []), user.id]);
    redirect(req, res, '/profile', 'Profile updated.');
  } catch (error) { next(error); }
});

app.get('/events', requireLogin, (req, res) => {
  const user = currentUser(req);
  const events = rows('SELECT * FROM events WHERE user_id = ? OR (user_id IS NULL AND ? = 1) ORDER BY user_id, event_date', user.id, user.admin);
  render(req, res, 'events', 'Manage events', { events });
});
app.post('/events', requireLogin, (req, res) => {
  const user = currentUser(req);
  const description = text(req.body.description, 100);
  const eventDate = text(req.body.event_date, 10);
  const parsedDate = new Date(`${eventDate}T00:00:00`);
  if (!description || !/^\d{4}-\d{2}-\d{2}$/.test(eventDate) || Number.isNaN(parsedDate.getTime()) || parsedDate.toISOString().slice(0, 10) !== eventDate) return redirect(req, res, '/events', 'Enter a description and valid date.', 'error');
  const eventId = id(req.body.event_id);
  const recurring = req.body.recurring === '1' ? 1 : 0;
  const owner = req.body.system_event === '1' && user.admin ? null : user.id;
  if (eventId) {
    if (!row('SELECT 1 FROM events WHERE id = ? AND (user_id = ? OR (user_id IS NULL AND ? = 1))', eventId, user.id, user.admin)) return res.sendStatus(404);
    run('UPDATE events SET description = ?, event_date = ?, recurring = ?, user_id = ? WHERE id = ?', description, eventDate, recurring, owner, eventId);
  } else run('INSERT INTO events (user_id, description, event_date, recurring) VALUES (?, ?, ?, ?)', owner, description, eventDate, recurring);
  redirect(req, res, '/events', 'Event saved.');
});
app.post('/events/:id/delete', requireLogin, (req, res) => {
  const user = currentUser(req);
  run('DELETE FROM events WHERE id = ? AND (user_id = ? OR (user_id IS NULL AND ? = 1))', id(req.params.id), user.id, user.admin);
  redirect(req, res, '/events', 'Event deleted.');
});

app.get('/admin', requireAdmin, (req, res) => {
  const users = rows('SELECT id, username, fullname, email, approved, admin, initial_family_id FROM users ORDER BY approved, fullname');
  const families = rows('SELECT f.*, COUNT(m.user_id) AS member_count FROM families f LEFT JOIN memberships m ON m.family_id = f.id GROUP BY f.id ORDER BY f.name');
  const familyMembers = rows('SELECT user_id, family_id FROM memberships');
  const guardianships = rows('SELECT g.guardian_id, g.child_id, gu.fullname AS guardian_name, cu.fullname AS child_name FROM guardianships g JOIN users gu ON gu.id = g.guardian_id JOIN users cu ON cu.id = g.child_id ORDER BY gu.fullname, cu.fullname');
  const categories = rows('SELECT * FROM categories ORDER BY name');
  const ranks = rows('SELECT * FROM ranks ORDER BY rank_order');
  render(req, res, 'admin', 'Administration', { users, families, familyMembers, guardianships, categories, ranks, settings: getSettings(), callbackOverride: process.env.OIDC_REDIRECT_URI });
});
app.post('/admin/users/:id', requireAdmin, (req, res) => {
  const userId = id(req.params.id);
  const target = row('SELECT * FROM users WHERE id = ?', userId);
  if (!target) return res.sendStatus(404);
  const action = req.body.action;
  if (action === 'approve') {
    db.transaction(() => {
      run('UPDATE users SET approved = 1 WHERE id = ?', userId);
      if (target.initial_family_id) run('INSERT OR IGNORE INTO memberships (user_id, family_id) VALUES (?, ?)', userId, target.initial_family_id);
    })();
    sendMessage(currentUser(req).id, userId, 'Your Gift Registry account has been approved. Sign in with the password you chose.');
    sendEmail(target.email, 'Gift Registry application approved', 'Your account was approved. You can now sign in with the password you chose.');
  } else if (action === 'reject' || action === 'delete') {
    if (target.id === currentUser(req).id) return redirect(req, res, '/admin', 'You cannot delete your own account.', 'error');
    if (action === 'reject') sendEmail(target.email, 'Gift Registry application denied', 'Your Gift Registry account request was denied.');
    run('DELETE FROM users WHERE id = ?', userId);
  } else if (action === 'toggle-admin') {
    if (target.id === currentUser(req).id) return redirect(req, res, '/admin', 'You cannot remove your own administrator access.', 'error');
    run('UPDATE users SET admin = ? WHERE id = ?', target.admin ? 0 : 1, userId);
  } else return res.sendStatus(400);
  redirect(req, res, '/admin', action === 'approve' ? 'Account approved.' : action === 'toggle-admin' ? 'Administrator access changed.' : 'User removed.');
});
app.post('/admin/families', requireAdmin, (req, res) => {
  const action = req.body.action;
  const familyId = id(req.body.family_id);
  const name = text(req.body.name, 100);
  if (action === 'insert' && name) run('INSERT INTO families (name) VALUES (?)', name);
  else if (action === 'update' && familyId && name) run('UPDATE families SET name = ? WHERE id = ?', name, familyId);
  else if (action === 'delete' && familyId) run('DELETE FROM families WHERE id = ?', familyId);
  else if (action === 'members' && familyId) {
    db.transaction(() => {
      run('DELETE FROM memberships WHERE family_id = ?', familyId);
      for (const memberId of toArray(req.body.member_ids).map(id).filter(Number.isInteger)) run('INSERT OR IGNORE INTO memberships (user_id, family_id) VALUES (?, ?)', memberId, familyId);
    })();
  } else return redirect(req, res, '/admin#families', 'Complete the family fields and try again.', 'error');
  redirect(req, res, '/admin#families', 'Family settings saved.');
});
app.post('/admin/guardianships', requireAdmin, (req, res) => {
  const guardianId = id(req.body.guardian_id);
  const childId = id(req.body.child_id);
  if (req.body.action === 'add' && guardianId && childId && guardianId !== childId) run('INSERT OR IGNORE INTO guardianships (guardian_id, child_id) VALUES (?, ?)', guardianId, childId);
  else if (req.body.action === 'remove') run('DELETE FROM guardianships WHERE guardian_id = ? AND child_id = ?', guardianId, childId);
  else return res.sendStatus(400);
  redirect(req, res, '/admin#guardianships', 'Guardian settings saved.');
});
app.post('/admin/catalog', requireAdmin, (req, res) => {
  const action = req.body.action;
  const name = text(req.body.name, 100);
  if (action === 'category-add' && name) run('INSERT INTO categories (name) VALUES (?)', name);
  else if (action === 'category-delete') run('DELETE FROM categories WHERE id = ?', id(req.body.id));
  else if (action === 'rank-add' && name) run('INSERT INTO ranks (title, rank_order) VALUES (?, ?)', name, Math.max(0, id(req.body.rank_order) || 0));
  else if (action === 'rank-delete') run('DELETE FROM ranks WHERE id = ?', id(req.body.id));
  else return res.sendStatus(400);
  redirect(req, res, '/admin#catalog', 'List options updated.');
});
app.post('/admin/announcements', requireAdmin, (req, res) => {
  const message = text(req.body.message, 2000);
  const recipients = req.body.recipients === 'all' ? rows('SELECT id FROM users WHERE approved = 1 AND id <> ?', currentUser(req).id).map((account) => account.id) : toArray(req.body.recipient_ids).map(id).filter(Number.isInteger);
  if (!message || !recipients.length) return redirect(req, res, '/admin#announcements', 'Enter a message and choose at least one recipient.', 'error');
  db.transaction(() => recipients.forEach((recipientId) => sendMessage(currentUser(req).id, recipientId, message)))();
  redirect(req, res, '/admin#announcements', 'Announcement sent.');
});
app.post('/admin/settings', requireAdmin, (req, res) => {
  const current = getSettings();
  const bools = ['newuser_requires_approval', 'shop_requires_approval', 'anonymous_purchasing', 'show_own_events', 'allow_multiples', 'hide_zero_price', 'oidc_enabled', 'oidc_auto_provision', 'oidc_auto_approve', 'smtp_secure'];
  const values = Object.fromEntries(bools.map((key) => [key, req.body[key] === '1' || (Array.isArray(req.body[key]) && req.body[key].includes('1')) ? '1' : '0']));
  for (const key of ['event_threshold', 'items_per_page', 'smtp_port', 'notify_threshold_minutes']) {
    const value = Number(req.body[key]);
    const max = key === 'event_threshold' ? 3650 : key === 'items_per_page' ? 100 : key === 'notify_threshold_minutes' ? 1440 : 65535;
    if (!Number.isInteger(value) || value < 1 || value > max) return redirect(req, res, '/admin#settings', `Invalid value for ${key}.`, 'error');
    values[key] = String(value);
  }
  for (const key of ['currency_symbol', 'oidc_issuer', 'oidc_client_id', 'oidc_scopes', 'oidc_redirect_uri', 'oidc_prompt', 'smtp_host', 'smtp_username', 'email_from']) values[key] = text(req.body[key], 500);
  values.oidc_client_secret = text(req.body.oidc_client_secret, 1000) || current.oidc_client_secret;
  values.smtp_password = text(req.body.smtp_password, 1000) || current.smtp_password;
  setSettings(values);
  oidcClients.clear();
  redirect(req, res, '/admin#settings', 'Settings saved.');
});

app.get('/health', (req, res) => res.json({ status: 'ok' }));
app.use((req, res) => res.status(404).render('layout', { view: 'error', title: 'Not found', page: { message: 'That page could not be found.' }, user: currentUser(req), csrf: csrfToken(req), notice: null, settings: getSettings() }));
app.use((error, req, res, next) => {
  console.error(error);
  if (res.headersSent) return next(error);
  const message = error.code === 'SQLITE_CONSTRAINT_UNIQUE' ? 'That value is already in use.' : process.env.NODE_ENV === 'production' ? 'Something went wrong. Please try again.' : error.message;
  res.status(500).render('layout', { view: 'error', title: 'Something went wrong', page: { message }, user: currentUser(req), csrf: csrfToken(req), notice: null, settings: getSettings() });
});

app.listen(port, '0.0.0.0', () => console.log(`Gift Registry listening on port ${port}`));
