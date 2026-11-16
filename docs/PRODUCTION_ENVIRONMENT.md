# Production environment

Build and deploy from reviewed source. Never copy a developer `.env` into a release.

## Backend

Set `APP_ENV=production`, `APP_DEBUG=false`, a generated `APP_KEY`, and an HTTPS
`APP_URL`. Configure the MariaDB `DB_*` values and list only real frontend origins
in `CORS_ALLOWED_ORIGINS`.

Configure real `MAIL_*` delivery, `QUEUE_CONNECTION`, `CACHE_STORE`,
`SESSION_SECURE_COOKIE=true`, `FILESYSTEM_DISK`, and `OCTANE_SERVER=frankenphp`.
Set the selected queue's `*_RETRY_AFTER` above the longest remaining job timeout.

When TLS terminates at a reverse proxy, set `TRUSTED_PROXIES` to a comma-separated
list of that proxy's IP addresses or CIDRs. An empty value trusts no proxy. Forwarded
host headers are intentionally not trusted; configure the public URL with `APP_URL`.

Document verification is manual. Program guidance uses the deterministic matcher;
no AI provider configuration is required.

Run `php artisan portal:production-audit --json` on the deployed host before and
after a release. The complete backup, bootstrap-account, migration, worker, scheduler,
smoke-test, and cleanup procedure is in `docs/PRODUCTION_DEPLOYMENT_RUNBOOK.md`.

## Frontend releases

Release builds require explicit public HTTPS API endpoints. Supply variables from
the release environment; do not create or commit production env files.

```text
VITE_API_BASE_URL=https://api.example.edu/api
VITE_DESKTOP_API_BASE_URL=https://api.example.edu/api
VITE_API_TIMEOUT_MS=15000
```

`npm run build` and `npm run mobile:build` use `VITE_API_BASE_URL`.
`npm run desktop:build` uses `VITE_DESKTOP_API_BASE_URL`. Production builds reject
HTTP, localhost, emulator, and private-network endpoints. Development commands keep
support for local endpoints.
