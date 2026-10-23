# Gift Registry

A family gift registry for maintaining wish lists, sharing lists with permission, and reserving gifts so the same item is not bought twice. This repository's current application is Node.js with SQLite; the former PHP implementation remains under `src/` as a legacy reference.

## Features

- Add gift ideas with a description, approximate price, retailer, optional URL, category, rank, notes, and quantity.
- Share a unified view of your own ideas and the lists you can access.
- Request and approve shopping access, with an administrator setting to make access requests immediate.
- Reserve, purchase, return, and release individual quantities. Other shoppers can be hidden from view.
- Create an account with administrator approval, plus optional OIDC sign-in, verified-email account matching, provisioning, and approval controls.
- Manage family groups and memberships, guardian/child relationships, categories, and custom ranks.
- Add recurring or one-time events, receive upcoming reminders, follow list changes, and use inbox messages or announcements.
- Optional SMTP email for account and message notifications.

## Run locally

Requires Node.js 22 or later.

```sh
npm install
npm start
```

Open `http://localhost:3000`. On first run, choose **Create the registry administrator** to create the first family and administrator account. SQLite data and sessions are stored under `data/` by default; set `DATA_DIR` to use another location.

## Run in Docker

```sh
docker compose up --build
```

Then open `http://localhost:8080` and complete first-run setup. Docker stores the SQLite database and sessions in the named `giftreg_data` volume. For production, set a long random `SESSION_SECRET` in `.env` (and optionally set `APP_PORT` or `OIDC_REDIRECT_URI`). If no `.env` exists, start from `.env.example`, then run:

```sh
docker compose -f docker-compose.prod.yml up --build -d
```

See [README.docker.md](README.docker.md) for deployment and OIDC details.

## OpenID Connect

Sign in as an administrator and open **Admin > Settings**. Configure the issuer URL, client ID, client secret, and scopes (`openid email profile` by default). Provisioning and automatic approval are independent settings. The callback is `/auth/oidc/callback`; configure the exact public URL with `OIDC_REDIRECT_URI` when deploying behind a reverse proxy. The application validates state, nonce, PKCE, issuer, and token signature through the OIDC client library.

## Email

Configure the SMTP host, port, sender address, optional authentication, and TLS mode in **Admin > Settings**. If SMTP is not configured, in-app messages and notifications continue to work without email delivery.

## Legacy PHP version

The original PHP source and documentation are kept in [src](src/README.md). The root Docker setup now runs the Node.js application.
