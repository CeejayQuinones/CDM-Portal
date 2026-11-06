# Production deployment and database bootstrap

This runbook preserves an existing CDM Portal database. Never use `migrate:fresh`,
`migrate:refresh`, `db:wipe`, `TRUNCATE`, or an uncontrolled cascading delete on a
deployed database.

## 1. Inventory before mutation

Run the read-only audit on the deployed backend host:

```bash
php artisan portal:production-audit --json
php artisan migrate:status
php artisan schedule:list
php artisan queue:failed
```

The audit does not print credentials, provider keys, tokens, profile details, or
academic values. A username that matches a development fixture is only a cleanup
candidate. Its dependencies and ownership must be reviewed before deletion.

Verify the public domain separately with HTTPS requests to `/up` and safe GET-only
API routes. Confirm the reverse proxy or Cloudflare Tunnel process, certificate,
queue worker, scheduler, and deployed frontend service on the actual host; these
cannot be inferred from source code.

## 2. Backup and restoration gate

Before migrations, seeding, or cleanup:

1. Create a transaction-consistent MariaDB dump including routines and triggers.
2. Record its timestamp, byte size, checksum, database host, and responsible operator.
3. Restore it into an isolated database with a different name.
4. Run `php artisan migrate:status` and the read-only audit against the restored copy.
5. Compare critical table counts with the source.

Do not proceed based only on the existence of a dump file. A restoration test is the
backup verification.

## 3. Production configuration

Required values include:

```text
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<backend-domain>
FRONTEND_URL=https://<frontend-domain>
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CORS_ALLOWED_ORIGINS=https://<frontend-domain>
QUEUE_CONNECTION=database
```

Set the real MariaDB, SMTP, cache, filesystem, trusted-proxy, and logging values in
the deployment secret store. Do not copy a developer `.env`, change `APP_KEY`, or
place secrets in frontend build variables.

## 4. Initial accounts

On a new database, production seeding creates reference data plus exactly one Admin
and one Registrar Staff account. Account values come only from server-side
environment configuration:

```text
PRODUCTION_BOOTSTRAP_ENABLED=true
PRODUCTION_ADMIN_USERNAME=
PRODUCTION_ADMIN_PASSWORD=
PRODUCTION_ADMIN_EMAIL=
PRODUCTION_ADMIN_FIRST_NAME=
PRODUCTION_ADMIN_LAST_NAME=
PRODUCTION_REGISTRAR_USERNAME=
PRODUCTION_REGISTRAR_PASSWORD=
PRODUCTION_REGISTRAR_EMAIL=
PRODUCTION_REGISTRAR_FIRST_NAME=
PRODUCTION_REGISTRAR_LAST_NAME=
PRODUCTION_REGISTRAR_EMPLOYEE_NUMBER=
```

Passwords require at least 12 characters with uppercase, lowercase, number, and
symbol characters. Seeding refuses username, email, or employee-number conflicts.
It does not reset an existing password and stops if multiple Admin or Registrar
accounts already exist. After the first successful seed, remove the plaintext
bootstrap passwords from the runtime environment and set
`PRODUCTION_BOOTSTRAP_ENABLED=false`.

Run only after reviewing the migration preview:

```bash
php artisan migrate --pretend
php artisan migrate --force
php artisan db:seed --force
php artisan optimize
```

Reload Octane and queue workers through the deployment service manager after the
release. Invoke `php artisan schedule:run` every minute using one scheduler owner.

## 5. Existing account cleanup

Production seeding never deletes existing accounts. If the audit finds demonstration
identities:

1. Confirm the record is fixture-owned rather than a legitimate account that happens
   to use a familiar username.
2. Review every dependency shown by the audit and query deeper child records.
3. Preserve accounts with later manual or operational activity.
4. Prepare a transaction-scoped, dependency-ordered deletion list with exact IDs and
   row counts.
5. Obtain explicit approval for that exact list after the restore test.

Delete deepest owned children first, then workflow parents, academic identities,
profiles, tokens/sessions, and finally Users. Never disable foreign keys. Do not use
the local large-dataset cleanup commands in production; they intentionally refuse.

## 6. Deployment verification

After deployment, verify:

- Admin and Registrar can log in only through Desktop.
- Professor, Student, and Guest can log in through Website and Android, not Desktop.
- Cross-platform token reuse is denied.
- `/up`, MariaDB, queues, scheduler, storage delivery, and SMTP are operational.
- Failed-job count is zero or every failure has an assigned resolution.
- Frontend assets call the HTTPS production API without mixed content.
- Monitoring AI status matches its approved provider configuration. No Student
  evidence is sent until provider approval and privacy review are complete.
- Logs contain no credentials, claim codes, bearer tokens, prompts, provider bodies,
  or private academic data.

Use read-only smoke tests unless the validation owner explicitly approves a named
test account and mutation procedure.
