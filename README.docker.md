# Docker Deployment

The root Docker files run the Node.js edition with SQLite. No separate database container or initialization step is required.

## Local use

```sh
docker compose up --build
```

Open `http://localhost:8080` and use **Create the registry administrator** for the first-run setup. The named `giftreg_data` volume stores the SQLite database and login sessions.

## Production

Add a long random value for `SESSION_SECRET` to `.env` (or start from `.env.example` if no `.env` exists). Set a public callback URL if OIDC will be used:

```env
SESSION_SECRET=replace-with-a-long-random-secret
APP_PORT=8080
OIDC_REDIRECT_URI=https://registry.example.org/auth/oidc/callback
```

Start the production configuration:

```sh
docker compose -f docker-compose.prod.yml up --build -d
```

The app listens on port 3000 in the container. `APP_PORT` selects the host port. The compose file trusts forwarded proxy headers; only use it behind a proxy you control. Configure TLS termination and forward the original host and protocol.

## OIDC setup

Register `https://your-public-host/auth/oidc/callback` with the identity provider, then enter the issuer URL, client ID, and client secret in **Admin > Settings**. `OIDC_REDIRECT_URI` pins the exact callback used by authorization and token exchange when the application is behind a reverse proxy. You can instead save a callback URL in Admin Settings. OIDC sign-in is disabled by default. Provisioning and automatic approval are separate options.

## Email

SMTP is optional and configured in **Admin > Settings**. Without SMTP, in-app account notifications, item updates, and direct messages remain available.

## Configuration

- `SESSION_SECRET`: required for the production compose file.
- `APP_PORT`: public host port, default `8080`.
- `OIDC_REDIRECT_URI`: optional fixed OIDC callback.
- `DATA_DIR`: directory used for SQLite and session storage; `/data` in Docker.
- `TRUST_PROXY`: set to `1` only when behind a trusted reverse proxy.
