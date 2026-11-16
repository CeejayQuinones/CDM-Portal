# IT validation deployment (test only)

This is a test release for Colegio de Montalban IT validation and presentation, not approval for production or real student data. Use synthetic or explicitly approved test records. Do not point the test clients or backend at the production API or database.

## Current preparation status (2026-11-16)

- Local frontend tests passed 106/106; the complete backend SQLite suite passed 573/573 with 6,139 assertions.
- Website, Android, and Desktop renderer builds succeeded locally. Android assets synchronized; no native APK was compiled because this machine lacks Java/Android SDK.
- A disposable Linux Desktop package assembled from the locally installed Electron binary, but no Windows installer or installed-client test was completed. The disposable package was removed.
- Connected Composer and npm advisory checks could not reach Packagist/npm from this workspace; an offline npm audit reporting zero findings is **not** a current security clearance. CI must rerun connected audits on the approved release revision.
- No test Website/API hostnames or test database exist yet. No deployment, migration, or production change has been made. The local generated artifacts are not approved test-release artifacts.

The current Cloudflare Pages Website `https://cdm-portal.pages.dev`, GCE VM `cdm-portal-server`, API `https://api.cdmportal.xyz`, and MySQL database `cdm_portal` are **out of scope** for this release. No separate test hosts or database have been provisioned yet. Do not upload the locally generated `dist/`, `dist-mobile/`, or Desktop package: their existing local build variables may point to the live API. The operator will provision the test infrastructure manually; this document does not authorize changes to the live project, VM, DNS, or database.

## 1. Infrastructure the operator must provision

1. Create a **new Cloudflare Pages project** for IT validation, connected only to an approved release branch/revision. Do not change the existing Pages project. Set the project root directory to `CDM_Frontend`, build command to `npm ci && npm run it:web:build`, output directory to `dist`, and project build variables `NODE_VERSION=24` and `SKIP_DEPENDENCY_INSTALL=1` (the command performs its own locked install). Restrict automatic branch deployments so an ordinary push to `main` does not silently publish test changes. Record the resulting exact HTTPS Pages origin; do not assume an example hostname is available. Cloudflare's [Git integration](https://developers.cloudflare.com/pages/get-started/git-integration/), [build configuration](https://developers.cloudflare.com/pages/configuration/build-configuration/), [build-image variables](https://developers.cloudflare.com/pages/configuration/build-image/), and [branch controls](https://developers.cloudflare.com/pages/configuration/branch-build-controls/) document these settings.
2. Create a **new GCE backend VM** (or a separately isolated backend deployment), not a deployment to `cdm-portal-server`. Use a new HTTPS API hostname/certificate, a dedicated service account and firewall rules, supported PHP/Composer/runtime dependencies, persistent application storage, a queue worker, a scheduler, logs, and a named operator. Avoid copying a production VM disk image because it can carry production `.env`, database files, or user data. Restrict administrative access; Google documents [VM creation](https://cloud.google.com/compute/docs/instances/create-start-instance) and [SSH access controls](https://cloud.google.com/compute/docs/connect/ssh-best-practices/network-access).
3. On that test environment, provision an **isolated MySQL-compatible database**, for example `cdm_portal_it_validation`, with a test-only account authorized only for that database. Do not replicate or import `cdm_portal` by default. Use synthetic records; any approved data copy requires a separate anonymization and consent plan. The exact engine/version must be checked against the repository's MariaDB CI gate and the deployed PHP driver.
4. Prepare the **test backend configuration** by merging [the test overlay](it-validation-backend.env.example) with `backend/.env.example` on the new VM, replacing all placeholders. Generate a **new** `APP_KEY` on that test VM after confirming it is isolated; never rotate, copy, or expose the live key. Set `APP_ENV=production`, `APP_DEBUG=false`, test-only `APP_URL`, `FRONTEND_URL`, MySQL credentials, explicit CORS, secure persistent sessions, SMTP usable for OTP, queue/cache/storage, and test-only OAuth callback/credentials if needed. `backend/.env.example` still defaults to the live database name, so do not deploy it unchanged. Do not store runtime secrets in Git, Cloudflare Pages build variables, or a client bundle.

**Account activation/registration hold:** the current code still invokes Turnstile for guest email verification and student account activation, even though ordinary login no longer shows that widget. In `APP_ENV=production`, the API throws if `TURNSTILE_ENABLED=false`; the frontend also blocks those forms without `VITE_TURNSTILE_SITE_KEY`. For full IT validation without changing existing features, provision a **separate test Turnstile widget**, put its public site key in the Website/Android build variable `VITE_TURNSTILE_SITE_KEY`, and put that same site key plus its secret key and exact expected hostnames in the new backend's `TURNSTILE_*` variables. Do not use Cloudflare's testing keys with `APP_ENV=production`, or reuse production keys. Verify both Pages and Android WebView activation on installed clients; Android's `localhost` hostname requires explicit test-widget planning. Cloudflare documents [environment separation](https://developers.cloudflare.com/turnstile/get-started/) and [hostname management](https://developers.cloudflare.com/turnstile/additional-configuration/hostname-management/). If the intended release should remove Turnstile from these flows too, that is a separate authentication change and this validation gate stays open until it is implemented and retested.

The new API must allow the exact Pages Website origin. This release includes Android and Desktop, so also assess their origins: the Android WebView uses `https://localhost`, and the packaged Electron renderer uses `Origin: null`. `null` is a broad CORS allowance, not a unique desktop identity; retain the application's authentication/authorization controls and do not treat CORS as authentication. Use the explicit `CORS_ALLOWED_ORIGINS` list in the test overlay only after confirming the actual origins on installed test clients. Do not copy the development-origin list wholesale.

Use [MySQL's backup and restore guidance](https://dev.mysql.com/doc/refman/8.4/en/using-mysqldump.html) for the *test* database. No dump, migration, or audit in this plan should target the existing live database.

## 2. Configure each test client build

After provisioning, set these non-secret release-build variables in the **new Pages project** and in each native-package build environment (not in the existing Pages project):

```text
IT_VALIDATION_WEB_URL=https://<provisioned-test-website-origin>
IT_VALIDATION_API_URL=https://<provisioned-test-api-origin>
IT_VALIDATION_DB_DATABASE=<provisioned-test-database-name>
VITE_API_BASE_URL=https://<provisioned-test-api-origin>/api
VITE_DESKTOP_API_BASE_URL=https://<provisioned-test-api-origin>/api
# Also set VITE_TURNSTILE_SITE_KEY to the new test widget's public key for Website/Android.
```

Use the **actual** Pages/API origins, without trailing paths on the `IT_VALIDATION_*_URL` variables; `VITE_*_API_BASE_URL` must end in `/api`. `IT_VALIDATION_DB_DATABASE` is a non-secret build assertion, **not** a database connection setting. Vite mode files currently contain the live API URL; the explicit build-environment `VITE_*` values must override them. Check the generated assets for the exact test API before distribution, and stop if the live API appears. Do not put DB credentials, SMTP credentials, or an `APP_KEY` in `VITE_*` variables.

Run `npm run it:check` in `CDM_Frontend`, then `npm run it:web:build`, `npm run it:mobile:build`, and `npm run it:desktop:renderer` as needed. Each IT build script checks that the Website, API, and database names are separate from the existing deployment and that its client points to the declared test API. The check does **not** create infrastructure, inspect the backend's actual DB connection, or prove a host is reachable. Do not use the ordinary `npm run build`/`mobile:build`/`desktop:build` scripts as a substitute for this test-target check.

## 3. Release source and hold points

1. Review the complete worktree and make an approved, immutable release commit. Do not deploy a mixture of the current branch and uncommitted files. Exclude local `.env` files, generated `dist*` and `release/` outputs, the nested `CDM-Portal-Desktop/` copy, and local recovery/data files.
2. Run the `Release technical gates` workflow on that exact revision. The frontend test and renderer jobs, Android native debug build, disposable Windows Desktop installer build, MariaDB migration/test job, and dependency audits must be reviewed individually. A renderer build alone does not prove the native app works. CI's synthetic API endpoint and unsigned packages are build checks, not deployable IT-validation artifacts.
3. Record the test Website origin, test API origin, test VM identity, test database host/name, release commit, and rollback owner. Review `docs/PRODUCTION_DEPLOYMENT_RUNBOOK.md` for backup/restore and migration safety; never use `migrate:fresh` or `migrate:refresh` on a persistent database.
4. On the **new test VM only**, confirm the machine identity and that the effective `APP_URL` and `FRONTEND_URL` are the test URLs. Verify the database host and name from the effective Laravel configuration and a read-only `SELECT DATABASE()` query. The connected database must be `cdm_portal_it_validation` (or the approved alternate), never `cdm_portal`. Do this before any migration, seed, or `php artisan optimize`/config cache. If the target is wrong or ambiguous, stop.

   ```bash
   php artisan tinker --execute="dump(config('app.url'), config('app.frontend_url'), config('database.connections.mysql.host'), config('database.connections.mysql.database'), DB::select('SELECT DATABASE() AS selected_database, @@hostname AS server_hostname'));"
   ```

   This prints only target identity/configuration, not DB passwords. It is a read-only query, but must still be run on the new test VM, never the live VM.
5. Run `php artisan portal:production-audit --json`, `php artisan migrate:status`, and `php artisan migrate --pretend` on the test backend. On a new empty database, note that the audit may report missing tables until migrations run. Inspect pending migration SQL, especially appointment, scheduling, and legacy-import tables. Before *subsequent* migrations, create and restore-test a backup of the test database. Use `php artisan migrate --force` only after the operator confirms the exact test target. If initial test accounts are needed, use the bootstrap procedure in `docs/PRODUCTION_DEPLOYMENT_RUNBOOK.md` with **test-only** credentials; disable bootstrap and remove plaintext passwords afterward.

## 4. Platform checks

| Platform | Build and delivery | IT validation checks |
| --- | --- | --- |
| Website/PWA | Run `npm run it:web:build`, then deploy `dist/` to the test Website origin. | Login/OTP, role access, navigation, scheduling, grading, student records, document appointments, PWA update, and API/CORS failures in the browser console. |
| Desktop | Run `npm run it:desktop:win:build` on a connected Windows build host with the test API variables and a trusted signing identity. It checks the target, builds NSIS, rejects an incomplete installer, and verifies both Windows signatures. A renderer build is not an installer. | Registrar/Admin login, local file import/export, scheduling and grading workflows, restart/update, and API/CORS failures. |
| Android | Run `npm run it:mobile:build`, then `npx cap sync android` and compile a **signed release** APK with Java/Android SDK and the four `CDM_ANDROID_*` signing values. CI's debug APK uses a synthetic API and is **not** the distributable test package. | Install on a physical device, login/OTP, navigation, document upload/download/share, reconnect behavior, and API/CORS failures. |

The Website and Desktop renderer builds both write to `CDM_Frontend/dist/`; build them in separate workspaces or rebuild Website last before uploading `dist/`. Android uses `dist-mobile/`. Do not distribute an unsigned test package as a production release.

**Native app identity hold:** Android's Gradle `applicationId` and Electron's `appId`/product name are currently the normal CDM Portal identities. A test APK/installer can therefore conflict with an installed normal app. Until separate test package IDs and branding are intentionally implemented and validated, install the native test packages only on clean/dedicated validation devices or after the operator explicitly handles an existing installation and its local data. Do not tell validators that the packages can coexist with a production installation.

Windows installer integrity, code signing, and Android release signing are covered in [Desktop and Android installation trust](APP_INSTALLATION_TRUST.md). The existing local Windows `.exe` is incomplete and must not be distributed.

## 5. Go/no-go and recovery

Hold the rollout if the release commit is incomplete, the database target/backup is unverified, a required CI gate fails, or a client cannot reach the test API. Confirm IT accepts any known dependency-audit exception before distributing a test Desktop installer. After deployment, use named test accounts to smoke-test each platform and record device/browser, revision, timestamp, result, and issue owner. Revert the Website/Desktop/Android test artifact to the last verified version if needed; prefer a forward fix for additive database migrations rather than an automatic schema rollback.

Provisioning handoff: the operator should return the exact new Pages Website origin, test API origin, test VM identity, test DB host/name (no password), and the approved release commit. Only then can target-specific builds, CORS verification, CI review, and installed-client smoke tests close this release gate.
