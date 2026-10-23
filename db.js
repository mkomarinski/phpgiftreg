import Database from 'better-sqlite3';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.dirname(fileURLToPath(import.meta.url));
export const dataDir = path.resolve(process.env.DATA_DIR || path.join(root, 'data'));
fs.mkdirSync(dataDir, { recursive: true });

export const db = new Database(path.join(dataDir, 'giftreg.sqlite'));
db.pragma('journal_mode = WAL');
db.pragma('foreign_keys = ON');
db.pragma('busy_timeout = 5000');

db.exec(`
  CREATE TABLE IF NOT EXISTS families (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE COLLATE NOCASE
  );
  CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password_hash TEXT NOT NULL,
    fullname TEXT NOT NULL,
    email TEXT UNIQUE COLLATE NOCASE,
    approved INTEGER NOT NULL DEFAULT 0,
    admin INTEGER NOT NULL DEFAULT 0,
    comment TEXT NOT NULL DEFAULT '',
    email_msgs INTEGER NOT NULL DEFAULT 0,
    initial_family_id INTEGER REFERENCES families(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
  );
  CREATE TABLE IF NOT EXISTS memberships (
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    family_id INTEGER NOT NULL REFERENCES families(id) ON DELETE CASCADE,
    PRIMARY KEY (user_id, family_id)
  );
  CREATE TABLE IF NOT EXISTS guardianships (
    guardian_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    child_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    relationship_type TEXT NOT NULL DEFAULT 'parent',
    PRIMARY KEY (guardian_id, child_id),
    CHECK (guardian_id <> child_id)
  );
  CREATE TABLE IF NOT EXISTS categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE COLLATE NOCASE
  );
  CREATE TABLE IF NOT EXISTS ranks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    rank_order INTEGER NOT NULL DEFAULT 0
  );
  CREATE TABLE IF NOT EXISTS items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    description TEXT NOT NULL,
    price REAL NOT NULL DEFAULT 0,
    source TEXT NOT NULL DEFAULT '',
    rank_id INTEGER REFERENCES ranks(id) ON DELETE SET NULL,
    url TEXT,
    category_id INTEGER REFERENCES categories(id) ON DELETE SET NULL,
    comment TEXT NOT NULL DEFAULT '',
    quantity INTEGER NOT NULL DEFAULT 1 CHECK (quantity > 0),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
  );
  CREATE TABLE IF NOT EXISTS allocations (
    item_id INTEGER NOT NULL REFERENCES items(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    bought INTEGER NOT NULL DEFAULT 0 CHECK (bought IN (0, 1)),
    quantity INTEGER NOT NULL CHECK (quantity > 0),
    PRIMARY KEY (item_id, user_id, bought)
  );
  CREATE TABLE IF NOT EXISTS shoppers (
    shopper_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    owner_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    pending INTEGER NOT NULL DEFAULT 0 CHECK (pending IN (0, 1)),
    PRIMARY KEY (shopper_id, owner_id),
    CHECK (shopper_id <> owner_id)
  );
  CREATE TABLE IF NOT EXISTS subscriptions (
    publisher_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    subscriber_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    last_notified TEXT,
    PRIMARY KEY (publisher_id, subscriber_id),
    CHECK (publisher_id <> subscriber_id)
  );
  CREATE TABLE IF NOT EXISTS messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sender_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    recipient_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    message TEXT NOT NULL,
    is_read INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
  );
  CREATE TABLE IF NOT EXISTS events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
    description TEXT NOT NULL,
    event_date TEXT NOT NULL,
    recurring INTEGER NOT NULL DEFAULT 0 CHECK (recurring IN (0, 1))
  );
  CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);
  CREATE TABLE IF NOT EXISTS oidc_identities (
    issuer TEXT NOT NULL,
    subject TEXT NOT NULL,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    PRIMARY KEY (issuer, subject)
  );
`);

const defaults = {
  newuser_requires_approval: '1', shop_requires_approval: '1', anonymous_purchasing: '0',
  event_threshold: '30', show_own_events: '1', allow_multiples: '1', currency_symbol: '$',
  hide_zero_price: '0', items_per_page: '50', oidc_enabled: '0', oidc_issuer: '',
  oidc_client_id: '', oidc_client_secret: '', oidc_scopes: 'openid email profile',
  oidc_redirect_uri: '', oidc_auto_provision: '0', oidc_auto_approve: '0', oidc_prompt: '',
  smtp_host: '', smtp_port: '587', smtp_secure: '0', smtp_username: '', smtp_password: '',
  email_from: '', notify_threshold_minutes: '60',
};
const insertSetting = db.prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)');
for (const [key, value] of Object.entries(defaults)) insertSetting.run(key, value);

if (db.prepare('SELECT COUNT(*) AS count FROM categories').get().count === 0) {
  const names = ['Books', 'Music', 'Video Games', 'Clothing', 'Movies/DVD', 'Gift Certificates', 'Hobbies', 'Household', 'Electronics', 'Ornaments/Figurines', 'Automotive', 'Toys', 'Jewellery', 'Computer', 'Games', 'Tools'];
  const add = db.prepare('INSERT INTO categories (name) VALUES (?)');
  db.transaction(() => names.forEach((name) => add.run(name)))();
}
if (db.prepare('SELECT COUNT(*) AS count FROM ranks').get().count === 0) {
  const titles = ['Wouldn\'t mind it', 'Would be nice to have', 'Would make me happy', 'I would really, really like this', 'I\'d love to get this'];
  const add = db.prepare('INSERT INTO ranks (title, rank_order) VALUES (?, ?)');
  db.transaction(() => titles.forEach((title, index) => add.run(`${index + 1} - ${title}`, index + 1)))();
}
if (db.prepare('SELECT COUNT(*) AS count FROM events').get().count === 0) {
  db.prepare('INSERT INTO events (user_id, description, event_date, recurring) VALUES (NULL, ?, ?, 1)').run('Christmas', `${new Date().getUTCFullYear()}-12-25`);
}

export function getSettings() {
  return Object.fromEntries(db.prepare('SELECT key, value FROM settings').all().map(({ key, value }) => [key, value]));
}

export function setSettings(values) {
  const update = db.prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
  db.transaction(() => Object.entries(values).forEach(([key, value]) => update.run(key, String(value))))();
}
