# Production environment

Build and deploy from reviewed source. Never copy a developer `.env` into a release.

## Backend

Set `APP_ENV=production`, `APP_DEBUG=false`, a generated `APP_KEY`, and an HTTPS
`APP_URL`. Configure the MariaDB `DB_*` values and list only real frontend origins
in `CORS_ALLOWED_ORIGINS`.

Configure real `MAIL_*` delivery, `QUEUE_CONNECTION`, `CACHE_STORE`,
`SESSION_SECURE_COOKIE=true`, `FILESYSTEM_DISK`, and `OCTANE_SERVER=frankenphp`.
The selected queue should keep its `*_RETRY_AFTER` setting above the 120-second
document analysis timeout; database, Redis, and Beanstalkd defaults are 180 seconds.

When TLS terminates at a reverse proxy, set `TRUSTED_PROXIES` to a comma-separated
list of that proxy's IP addresses or CIDRs. An empty value trusts no proxy. Forwarded
host headers are intentionally not trusted; configure the public URL with `APP_URL`.

Document AI requires `AI_DOCUMENT_ANALYZER=gemini`, `GEMINI_API_KEY`,
`GEMINI_MODEL`, and `GEMINI_TIMEOUT`. Admission recommendation AI uses
`ADMISSION_GEMINI_API_KEY` and `ADMISSION_GEMINI_MODEL`. Monitoring AI remains
disabled unless `MONITORING_AI_ENABLED=true` and its provider settings are present.

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
