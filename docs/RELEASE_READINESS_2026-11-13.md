# CDM Portal release readiness — 2026-11-13

Status: **NOT READY for production activation**. Isolated tests and production dependency-subset audits are green, but MariaDB 10.4 CI has not run, the full npm audit still reports desktop/build-tool findings, and production infrastructure, backup restoration, and institutional UAT remain unverified. No production or persistent database operation was performed for this audit.

## Final CI gate preparation and connected dependency audit — 2026-11-13

**Status remains NOT READY.** There were no existing `.github/workflows` files. The new [release-technical-gates.yml](../.github/workflows/release-technical-gates.yml) is a proposed GitHub-hosted CI workflow, not an executed CI result. It triggers on an approved push to `fix/audit-findings` (or manual dispatch once GitHub makes the workflow available), uses a disposable `mariadb:10.4.34` service, asserts `VERSION()` begins with `10.4.` and `DATABASE()` equals the CI-only name, runs the complete Laravel migration sequence, then exercises Historical Grades, Enrollment, Grading, and authentication tests. Separate jobs run locked Composer and full npm audits. It has read-only repository permissions and no deployment step. [GitHub's service-container documentation](https://docs.github.com/en/actions/tutorials/use-containerized-services/use-docker-service-containers) explains the runner-local port mapping; [Docker Hub's MariaDB 10.4 image metadata](https://hub.docker.com/layers/library/mariadb/10.4/) identifies the 10.4.34 tag. The old 10.4 image is for compatibility testing only, not a production-server recommendation.

**MariaDB 10.4 remains unverified.** No local server/container runtime exists, and no CI run or persistent-database connection was made. A valid CI run must confirm the service starts, the migration sequence and status pass, and all selected tests use MySQL rather than PHPUnit's SQLite default. The workflow provides the connection through process environment variables; PHPUnit's non-forced `<env>` entries do not overwrite existing values, as documented in the [PHPUnit XML configuration manual](https://docs.phpunit.de/en/12.5/xml-configuration-file.html). A failure is a release blocker to investigate, not a reason to connect to `cdm_portal`.

Connected registry audits **did execute**. Initial `composer audit --locked --format=json` found eight advisories in five transitive, production-relevant packages. Narrow patch-level updates changed only `backend/composer.lock` and the ignored local `vendor` installation; `composer.json` was not changed:

| Locked package | Before → after | Initial advisory IDs | Initial severity from Composer |
| --- | --- | --- | --- |
| `phpseclib/phpseclib` | 4.0.2 → 4.0.3 | `PKSA-h7cz-q7zq-mdkt` | Not supplied |
| `symfony/http-foundation` | 7.4.20 → 7.4.23 | `PKSA-24ph-xkrh-y79j`, `PKSA-pr6q-2w5s-kzzf`, `PKSA-nxjs-s6hn-vb6m`, `PKSA-b2gg-vk1g-6d5q` | Not supplied |
| `symfony/http-kernel` | 7.4.20 → 7.4.23 | `PKSA-3gxv-7xhq-mgmz` | Not supplied |
| `symfony/mime` | 7.4.19 → 7.4.22 | `PKSA-wtxr-p26d-nn42` | Medium |
| `symfony/routing` | 7.4.20 → 7.4.23 | `PKSA-y8c5-1b7f-r1gj` | Not supplied |

Composer's post-update locked audit exited **0** with no advisories or abandoned packages. These packages are transitive from Laravel/Socialite and part of the API's production dependency graph; the updates were justified before processing real student data. A fresh CI audit is still required on the approved release revision.

Initial `npm audit --json` found 21 affected package entries (7 high, 11 moderate, 3 low); `npm audit --omit=dev --json` isolated one high-severity production dependency: transitive `follow-redirects` 1.16.0 via Axios. Its three advisories are `GHSA-q3jh-wrc6-jgq8`, `GHSA-8r9p-f939-6h3c`, and `GHSA-mrwp-f3q8-3g9p` (credential-header handling on redirects). The existing frontend lockfile now selects the published 1.16.3 patch with registry-provided integrity; `package.json` was not changed. `npm ci --ignore-scripts` succeeded, and the post-change **production-only** npm audit exited **0** with zero findings. This clears the current npm production dependency subset, not the full desktop/build-tool graph.

The post-change **full** npm audit still exits **1**: 20 affected package entries (6 high, 11 moderate, 3 low; no critical). High entries are direct `electron` and `vite`, plus transitive `@xmldom/xmldom`, `brace-expansion`, `extract-zip`, and `undici`; moderate/low entries include `electron-builder` and PWA/build-tool chains. Electron 39.8.10 is declared under `devDependencies` but is the packaged desktop runtime, so its high-severity [sandbox and protocol advisories](https://github.com/advisories/GHSA-j84w-jfhq-vhvj) cannot be dismissed as Website-only tooling. npm proposes Electron 45.3.1 (major) and other potentially breaking tooling changes; none was applied automatically. **Hold packaged Electron distribution** until an approved upgrade/mitigation and desktop regression/UAT plan resolves those findings. The workflow deliberately leaves the full npm audit failing so they remain visible; a green production-only npm audit is not a green overall release gate.

| Remaining high-severity package | Dependency class | Advisory examples reported by npm | Release relevance |
| --- | --- | --- | --- |
| `electron` | Direct dev dependency, packaged desktop runtime | `GHSA-hq2x-r82h-9wj4`, `GHSA-gr2m-v5gq-v685`, `GHSA-j84w-jfhq-vhvj`, `GHSA-9qh4-3jw8-366w` (plus eight more in the audit output) | Desktop release blocker pending major-version review and packaged-client UAT. |
| `extract-zip` | Transitive through Electron | `GHSA-jmr9-qjv8-65gv`, `GHSA-7pqw-9j4j-h8q3` | Electron installation/build dependency; assess trusted archive handling with the Electron upgrade. |
| `@xmldom/xmldom` | Transitive dev/build tooling | `GHSA-6mj3-qw4j-hgrw`, `GHSA-g53g-w8rj-fmg7` (plus eleven more) | Build-time XML input handling; not in npm's production subset. |
| `brace-expansion` | Transitive dev/build tooling | `GHSA-3jxr-9vmj-r5cp`, `GHSA-mh99-v99m-4gvg` (plus four more) | Build-tool denial of service; not in npm's production subset. |
| `undici` | Transitive dev/build tooling | `GHSA-2m5v-pch6-74hx`, `GHSA-xjj6-f33q-929c` (plus three more) | Build-tool HTTP/TLS handling; not in npm's production subset. |
| `vite` | Direct dev/build dependency | `GHSA-587h-7wmj-h5pm`, `GHSA-9jrq-w75r-8gcw` (plus three more) | Development server/build tool, not shipped in Website runtime; still requires reviewed remediation. |

The remaining moderate entries are `@electron/universal`, `app-builder-lib`, `dmg-builder`, `electron-builder`, `electron-builder-squirrel-windows`, `fast-uri`, `glob`, `minimatch`, `rimraf`, `vite-plugin-pwa`, and `workbox-build`; low entries are `@rollup/plugin-node-resolve`, `deepmerge`, and `serialize-javascript`. These are npm's package-entry severities, not a count of unique vulnerabilities or proof of exploitability in the deployed Website. The full audit output must be retained and triaged in the approved CI run.

Regression validation after the dependency changes: focused Laravel suite **160 passed, 1,678 assertions** on isolated SQLite; frontend **99/99 test files passed** after clean `npm ci`; Website/PWA production build passed (422 modules). No MariaDB tests, packaged Electron GUI, or physical Android device test was executed in this follow-up. No application authentication or grading code changed.

To execute the CI verification after explicit Git approval: (1) review a narrow staging allowlist that includes all intended currently untracked feature files, the two changed lockfiles, the workflow, and this report; keep recovery snapshots and all three stashes out of the release; (2) approve and create a commit, then push `fix/audit-findings`—the branch-specific `push` trigger starts CI automatically; (3) inspect all three job logs and the printed `VERSION()`/database name, migration status, focused test result, and both audit outputs; (4) fix any real MariaDB failure or remaining full-audit finding under a separately reviewed change, then rerun CI on the revised commit. Do not deploy or process real student data merely because the Website-only subset is green. GitHub manual dispatch is an alternative only when the workflow is available under the repository's Actions rules.

Concise deployment gate after CI: require a successful MariaDB 10.4 run; zero unresolved production advisories and an approved desktop advisory decision; reviewed release artifact and environment values; restored-test database/file backups; approved production migration window; then backend, Website/PWA, and separately packaged Android/Electron smoke/UAT. None of these deployment actions was performed.

## Earlier offline technical-gate follow-up — 2026-11-13

Historical record of the earlier sandboxed attempt; its registry-access findings were superseded by the connected audits above.

**Status remains NOT READY.** This follow-up made no application-code or dependency changes. The only change was to this report. The three stashes and existing uncommitted work remain untouched.

| Gate | Result | Evidence and limit |
| --- | --- | --- |
| MariaDB 10.4 runtime | **Blocked; not verified** | The workspace has a MariaDB 11.8 client and PHP `pdo_mysql`, but no `mariadbd`/`mysqld`, Docker, Podman, or cached MariaDB server package. No connection to the persistent `cdm_portal` database was attempted. SQLite tests cannot establish MariaDB 10.4 DDL, index, or FK compatibility. |
| Full migration sequence on MariaDB 10.4 | **Not run** | Requires a fresh disposable 10.4 service. The earlier SQLite in-memory Laravel suite did run migrations, but it is not a substitute for this gate. |
| Historical Grades, OTP, Enrollment, Grading, and lifecycle regressions | **Passed on SQLite** | Focused `php artisan test` run: 82 passed, 1,114 assertions (HistoricalGradesTest, LoginOtpAuthenticationTest, EnrollmentAcademicTest, GradingPhaseThreeTest, GradingPhaseTwoTest, StudentLifecycleIntegrationTest). It covers synthetic grade import/correction, OTP, prerequisites, publication, and the historical rollback guard. Other new migration rollback guards were not specifically exercised in this run and still need MariaDB validation. |
| Composer dependency audit | **Blocked; not clean** | `composer audit --locked --format=json --no-interaction` exited 100 because `repo.packagist.org` could not be resolved (`curl error 6`). No advisory response was received. |
| npm production dependency audit | **Blocked; not clean** | `npm audit --omit=dev --json` exited 1 because `registry.npmjs.org` returned `getaddrinfo EAI_AGAIN`. No advisory response was received. |

Therefore advisory IDs, affected package/version, severity, direct-versus-transitive status, remediation, and production exploitability **cannot be determined from these attempts**. No dependency was changed or automatically upgraded. Re-run both audits from a connected, trusted CI runner and retain their JSON outputs; classify each finding against `composer.json`/`composer.lock` or `package.json`/`package-lock.json`, then assess the deployed code path before selecting a narrowly scoped update. A failed registry request is not a zero-vulnerability result. [Composer audit](https://getcomposer.org/doc/03-cli.md#audit) queries configured advisory sources; [npm audit](https://docs.npmjs.com/cli/commands/npm-audit/) uses the registry advisory endpoint.

Safe MariaDB 10.4 verification procedure, **proposed only, not executed**:

1. On a connected CI runner with Docker or another approved disposable container service, pull and pin a MariaDB 10.4 image/digest. The [official MariaDB image](https://hub.docker.com/_/mariadb) has a 10.4 image lineage; verify the actual tag and `SELECT VERSION()` at test time. Run it without host database mounts or links to existing services, with generated test-only credentials and a unique database name such as `cdm_release_ci_<run_id>`. Bind only to loopback or the private CI network.
2. Build an ephemeral Laravel testing configuration that points **only** to that unique database. Do not copy or edit any real `.env` or use `cdm_portal`. Before mutation, assert `SELECT VERSION(), DATABASE()` shows 10.4 and the unique test database. Ensure PHPUnit's `phpunit.xml` SQLite overrides are replaced by a temporary CI-only configuration; otherwise a nominal MySQL test command may silently run on SQLite.
3. Run the complete migration sequence with `php artisan migrate --force` against the disposable database; check `migrate:status`, `information_schema.TABLES`, `STATISTICS`, `KEY_COLUMN_USAGE`, and `REFERENTIAL_CONSTRAINTS`. Verify all historical/OTP tables, composite indexes and foreign keys, no duplicate object creation, and identifiers at or below 64 characters. Test a second `migrate` for idempotence.
4. Run the focused tests listed above with synthetic fixtures on that same isolated database, including valid/invalid XLSX preview and confirmation, manual correction/audit, passed/failed/disputed prerequisite behavior, and rollback guards. Test rollback on a **separate fresh disposable database** so official synthetic rows and test fixtures cannot interfere with migration-order checks. Record the SQL mode, engine, collation, server version, migration output, and test results.
5. Destroy only the named CI container and its dedicated disposable volume after retaining logs. Do not use the existing MariaDB socket, `cdm_portal` database, persistent volumes, or production credentials. Any compatibility failure must be fixed from the captured SQL/error and rerun before changing release status.

The existing production-readiness P1 gates below remain open. No production, persistent, or real-student database was accessed; no deployment, Git history change, or environment-file overwrite occurred.

## Verified in this workspace

| Check | Result | Limit |
| --- | --- | --- |
| Branch and stashes | `fix/audit-findings`; three stashes preserved | Working tree is intentionally dirty. |
| Laravel full suite | 554 passed, 5,959 assertions | SQLite `:memory:` and synthetic fixtures; full run began before the rollback-guard test was added. |
| Historical Grades focused suite | 11 passed, 99 assertions | Includes XLSX upload/preview/confirm, ownership, audit, prerequisites, and rollback guard. |
| Frontend suite | 99 test files passed | Source/component and unit tests, not a live browser. |
| Laravel Pint | Passed; changed migration/test passed again after final edit | No frontend lint or format script exists in `package.json`. |
| Website/PWA | Production build passed; 422 modules, generated service worker | No live Cloudflare deployment. |
| Android | Production and development renderer builds passed; 418 modules each | No Capacitor sync, APK/AAB, or physical device test. |
| Electron | Production and development renderer builds passed; 418 modules each | No packaged installer or GUI test. |
| Routes | 239 registered routes; no duplicate method/path pairs detected | Runtime authorization is covered by tests, not live UAT. |
| Composer | Manifest valid; installed non-dev platform requirements passed on PHP 8.5.4 | The production host must be checked separately for PHP >= 8.3 and required extensions. |
| Dependency advisories | **Partly resolved** | Connected post-patch Composer and npm production-subset audits exited 0; full npm audit still has 20 desktop/build-tool package entries. Recheck all on the approved CI revision. |
| MariaDB 10.4 | **Not verified at runtime** | No disposable MariaDB server was available; no persistent database was used. |

Production Website, Android, and Electron renderer bundles contained `https://api.cdmportal.xyz/api` and no `http://localhost:8000` or `http://127.0.0.1:8000` API URL. Production `index.html` CSP permits the exact Cloudflare Turnstile script and frame origin. [Cloudflare's CSP reference](https://developers.cloudflare.com/turnstile/reference/content-security-policy/) confirms those directives. The policy is currently an HTML meta policy; host-level HTTP security headers remain an infrastructure decision.

## Source and package inventory

The working tree contains 90 modified tracked paths after this audit's `.gitignore` change, plus untracked implementation files and documents. See [RELEASE_FILE_INVENTORY_2026-11-13.md](RELEASE_FILE_INVENTORY_2026-11-13.md) for an exact path snapshot. The changes group into authentication/OTP/Turnstile, enrollment/OVRF, grading, historical grades, Student Records, document requests/retrieval, frontend navigation/UI, tests, and deployment documentation. The three stashes are separate historical work; their contents are not included in this release candidate.

This audit added only `.gitignore` release exclusions, the Historical Grades migration rollback guard, its regression test, and this report/inventory. Nothing was deleted. `.local-recovery/` contains approximately 3 GB of local snapshots, including a SQL dump. Office lock files, `:Zone.Identifier` files, and three malformed `backend/` scratch filenames are also excluded by `.gitignore`; they remain on disk. Review untracked PDF samples before packaging, because their provenance and suitability for distribution are unverified. Use an explicit staging allowlist; do not use `git add -A` or copy the workspace as a deployment artifact.

No merge-conflict markers were found in application source. Builds resolved frontend imports. The only global `git diff --check` noise is CRLF line endings in existing frontend/lock files; a check using `core.whitespace=cr-at-eol` found no substantive whitespace errors in scoped source. No confirmed SQL injection or broken route was found in the reviewed changed code; that does not establish a complete security penetration test or production performance benchmark.

## Priority risks and release gates

| Priority | Finding | Required action |
| --- | --- | --- |
| P0 | No confirmed code defect remains from this audit. | Keep the gate closed if any preflight or smoke test fails. |
| P1 | Production migration status and existing schema are unknown; the earlier local Historical Grades migration failed after a table could have been created. | Inspect `migrations`, `information_schema` tables/indexes/FKs on the target **read-only**. Rehearse all pending migrations on a disposable MariaDB 10.4 copy before production. Never assume the local failure describes production. |
| P1 | No verified production database and private/public file backup with a restore test. | Record backup identifiers and restore evidence before any migration or code switch. |
| P1 | Pages, backend host, DNS/TLS, CORS, OAuth, Turnstile, SMTP, cache, scheduler, queue, and storage settings are not verified in production. | Complete the environment checklist below and smoke-test the exact deployed origins. Hosting provider is not established by this repository. |
| P1 | No live Registrar/Student/Professor browser, Android device, or packaged Electron acceptance run. | Complete the UAT matrix below with institutional sign-off. |
| P1 | Connected audits found and fixed Composer and npm production-subset advisories, but the full npm audit still reports 20 entries, including high-severity Electron runtime findings. | Keep the full CI audit red and hold packaged Electron distribution until an approved major-version or mitigation/UAT decision. Re-run all audits on the release revision. |
| P1 | Historical subject matching intentionally requires an exact subject code in the student's assigned curriculum. Existing-student identity import and equivalency rules are not present as an approved workflow. | Registrar must approve the historical curriculum/equivalency process and reconcile unknown Student Numbers separately. |
| P2 | Website policy is meta CSP; HTTP CSP and other security headers are not configured in repository deployment files. | Validate report-only HTTP headers, then apply exact allowed sources at Cloudflare/API hosting. Do not loosen CSP for Cloudflare iframe warnings. |
| P2 | Student document request history returns a full array; some real-data workloads and synchronous SMTP can exceed local test timings. | Measure production-like response/SMTP times and queue depth before setting SLOs; change contracts only with evidence. |

## Database and migration checklist

The five **new, untracked** migrations in this worktree, in timestamp order, are:

1. `2026_11_11_000001_create_document_request_batches.php`: creates batch table, adds a nullable FK/index to existing `document_requests`.
2. `2026_11_11_000002_create_physical_retrieval_queue.php`: adds default-false document-type flag and task/link/event tables.
3. `2026_11_11_000003_create_ovrf_template_drafts.php`: creates private template-draft/version and event tables.
4. `2026_11_12_000001_create_login_otp_challenges_table.php`: creates short-lived login challenges; required before Website/Android OTP code is activated.
5. `2026_11_13_000001_create_historical_grade_records.php`: creates official records, events, and import previews; required before Registrar import/manual entry is activated.

Production may also have earlier pending committed migrations. Determine that from `php artisan migrate:status` and review each pending file before applying anything. The new migration names and generated index/FK identifiers were inspected against [MariaDB's 64-character identifier limit](https://mariadb.com/docs/server/reference/sql-structure/sql-language-structure/identifier-names); the longest relevant generated FK name is 61 characters. Historical Grades now uses a 29-character explicit composite-index name. Laravel schema constructs used by these migrations work in isolated SQLite tests; actual MariaDB 10.4 execution remains a required rehearsal. The first failed local Historical Grades attempt may have left `historical_grade_records` plus earlier indexes/FKs while recording no migration row, because MariaDB [implicitly commits DDL](https://mariadb.com/docs/server/reference/sql-statements/transactions/sql-statements-that-cause-an-implicit-commit). Do not run the corrected migration over any partial table without inspecting it.

Rollback boundaries: document group/retrieval/OVRF migrations refuse to discard populated history; Historical Grades now refuses rollback if official records, events, or preview rows exist. OTP rollback removes pending challenges. Ordinary `migrate:rollback` is not a safe recovery plan once real academic or document history is written. Treat production database restore as a separately authorized, coordinated outage because it can discard subsequent writes.

Before the approved maintenance window, an operator should:

1. Freeze writes, identify the exact backend release and target database, and capture a current consistent database backup plus `storage/app/private`, `storage/app/public`, and any other configured file disks. Record checksums and access controls. Restore both database and files into an isolated environment and verify representative records/downloads.
2. On the **target**, run read-only `php artisan migrate:status` and inspect `information_schema.TABLES`, `STATISTICS`, `TABLE_CONSTRAINTS`, and the `migrations` rows for all five paths. Check for partial tables/columns or name collisions. Escalate any discrepancy; do not drop or alter existing objects as an automatic repair.
3. On a fresh disposable MariaDB 10.4 database with an approved schema/data copy, run the five path-scoped migrations in order and exercise upload, OTP, document/retrieval, OVRF, and rollback guards. Record SQL mode, charset, engine, version, and timings. Do not use real student data in an uncontrolled environment.
4. Rehearse the exact command list below using `--pretend` on the approved target, inspect the SQL, then obtain change approval. Application code that depends on new tables must not receive traffic until the migrations and verification succeed.

Proposed production commands **for a later approved operator-run window only** (run from the deployed `backend/` directory, after resolving any earlier pending migrations):

```bash
php artisan migrate:status
php artisan migrate --pretend --path=database/migrations/2026_11_11_000001_create_document_request_batches.php
php artisan migrate --pretend --path=database/migrations/2026_11_11_000002_create_physical_retrieval_queue.php
php artisan migrate --pretend --path=database/migrations/2026_11_11_000003_create_ovrf_template_drafts.php
php artisan migrate --pretend --path=database/migrations/2026_11_12_000001_create_login_otp_challenges_table.php
php artisan migrate --pretend --path=database/migrations/2026_11_13_000001_create_historical_grade_records.php
```

After backups, rehearsal, maintenance approval, and target-schema verification, replace `--pretend` with `--force` for each path in the same order. Stop at the first failure, inspect actual tables/indexes/FKs and migration rows, and plan a forward repair. Do not invoke `migrate:fresh`, `migrate:refresh`, `db:wipe`, a destructive seeder, or an unreviewed blanket migration.

## Production environment checklist (names only; no secret values)

| Component | Verify |
| --- | --- |
| Backend identity/TLS | `APP_ENV=production`, `APP_DEBUG=false`, preserved `APP_KEY`, `APP_URL` at the HTTPS API origin, `FRONTEND_URL` at the actual Website origin, valid certificate, trusted proxy configuration. Never generate a new key over existing encrypted data. |
| Database | `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`; least privilege, backups, charset/collation, connectivity from the backend host. |
| Sessions/tokens | `SESSION_DRIVER` persistent, `SESSION_SECURE_COOKIE=true`, `SESSION_DOMAIN`/`SESSION_SAME_SITE` suited to the API OAuth callback, `SANCTUM_TOKEN_EXPIRATION=1440`; verify revocation and worker/config-cache consistency. Bearer-token API has `supports_credentials=false` in CORS. |
| Origins | `CORS_ALLOWED_ORIGINS` explicitly lists each active Website origin (`https://cdm-portal.pages.dev`, `https://cdmportal.xyz` if used) plus approved packaged Electron/Capacitor origins after runtime verification. The literal `null` origin needs a documented Electron decision because it covers opaque origins broadly. No `*`. |
| Turnstile | `TURNSTILE_ENABLED=true`, production `TURNSTILE_SITE_KEY`, `TURNSTILE_SECRET_KEY`, `TURNSTILE_EXPECTED_HOSTNAMES` for exact active Website/Android hostnames; production test keys are rejected by code. Confirm Cloudflare widget hostname allowlist and Siteverify reachability. |
| Google OAuth | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` matching the API callback; registered authorized origins/redirects in Google Console; functioning persistent/shared session and cache across workers. |
| Mail | `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_SCHEME`/TLS, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`; real deliverability and SPF/DKIM/DMARC. Login OTP refuses log/array delivery. |
| Runtime/cache/queue | `CACHE_STORE` shared if multiple backend nodes, `QUEUE_CONNECTION` async, queue driver credentials, `DB_QUEUE_RETRY_AFTER` > document-analysis job timeout (120 seconds), configured worker for `document-analysis`, scheduler every minute, `OCTANE_SERVER` and HTTPS/proxy settings if Octane/FrankenPHP is used. `PRODUCTION_BOOTSTRAP_ENABLED` disabled after any separately approved bootstrap. |
| Files | Persistent private/public disks, writable `storage` and `bootstrap/cache`, controlled `public/storage` link if public avatars are used, file backup/restore, PDF/Excel PHP extensions and upload size limits. |
| Pages/public build | Root `CDM_Frontend`, `npm ci` then `npm run build`, output `dist`, build-time `VITE_API_BASE_URL=https://api.cdmportal.xyz/api`, appropriate Node version, production demo flag absent/false. `VITE_*` variables are public build inputs, never secret storage. |
| Android/Electron | Approved production API variables (`VITE_API_BASE_URL` and `VITE_DESKTOP_API_BASE_URL`), separate signed release builds, final CSP/CORS verification, platform storage and logout tests. |

The local `.env.production`, `.env.mobile`, and `.env.desktop` files are Git-ignored. Their local values cannot be assumed to exist in Cloudflare Pages or release CI. [Cloudflare Pages build configuration](https://developers.cloudflare.com/pages/configuration/build-configuration/) requires the monorepo root/build settings and build-time variables to be configured there. The backend hosting provider and actual production service topology are not established in this repository.

## Proposed rollout and recovery

1. Approve a file allowlist and review a staged diff, lockfiles, licenses, secret scan, and the connected dependency-audit reports. Produce a reproducible immutable release artifact/revision; keep the three stashes untouched. Do not package `.env`, `.local-recovery`, generated `dist`, local SQL/PDF scratch files, or OS metadata. Obtain separate Git approval before commit/push/merge.
2. Complete infrastructure and institutional gates above. Configure Pages and backend environment in their own secret/config stores. Verify Google, Turnstile, SMTP, CORS and TLS with test identities. Record current backend and Pages revisions.
3. Take and restore-test database/file backups. Put dependent backend writes behind an approved maintenance window. Rehearse and apply only reviewed pending migrations, then verify tables, constraints, counts, and migration rows. No academic data backfill is implied by these migrations.
4. Activate the new backend release with PHP dependencies installed from the lockfile, writable storage and caches, and production debug disabled. Run the approved Laravel cache commands (`php artisan optimize` is the Laravel 13 combined option) and reload Octane/queue processes through the actual host process manager. Confirm the queue worker and scheduler are running. [Laravel's deployment guide](https://laravel.com/framework/docs/13.x/deployment) documents cache and worker reload requirements.
5. Run backend health/auth/authorization smoke tests. Then deploy the Website/PWA revision to Cloudflare Pages with the reviewed production API URL. Test cache/service-worker update and the exact live domain. Hold Android and Electron distribution until device/packaged-client UAT passes.
6. Monitor 4xx/5xx, Siteverify and Google callback failures, OTP delivery latency/errors, queue failures, scheduled tasks, document storage errors, import validation failures, and database performance. Review logs for secrets and PII. Keep a named incident owner and a stop/go decision record.

Recovery: revert an unhealthy Pages deployment to the previously verified Pages revision. For backend issues, prefer a forward code fix while keeping additive schema; confirm the prior backend is compatible before switching. Reverting to code that bypasses Turnstile/OTP would weaken login and requires an explicit security decision or maintenance hold. Do not automatically roll back the new database migrations after official records or document history exist. A full database restore requires matching file restore, a write freeze, and acceptance of writes lost since backup. MariaDB DDL can persist after a failed migration, so inspect and repair forward rather than assuming transaction rollback.

## Colegio de Montalban UAT matrix

Status legend: **A** = isolated automated coverage passed; **M** = manually tested in an approved environment; **N** = not yet manually verified; **I** = institutional policy/identity decision required. No live UAT was performed in this audit.

| Journey | Required positive and negative checks | Status |
| --- | --- | --- |
| Guest registration | Email proof, Turnstile, duplicate/expired code, rate limits; role isolation. | A, N |
| Website/Android login | Password and Google sign-in → Turnstile → email OTP → token; wrong/expired/reused code, resend cooldown, logout, expiry, password change. No session before OTP. | A, N |
| Electron staff login | Registrar/Admin password login and role-only routes; no Turnstile/OTP regression; packaged file-origin CORS/CSP and token storage. | A, N |
| Admission | Applicant identity, exam policy, result/recommendation, audit, denied cross-applicant access. | A, N, I for official exam/decision policy |
| Enrollment | Admission handoff, student identity, first-time/reapplication, documents, academic proposal, prerequisites, conflicting/finalized states, OVRF. | A, N, I for curriculum rules |
| Professor Grading | Assessment entry, published immutable snapshots, late release scheduler, student access, grading scale, duplicate/unauthorized publication. | A, N, I for official grading scale |
| Student Records | Registrar search/profile/report, document history, cross-student isolation, light/dark and narrow layout. | A, N |
| Historical Grades | Template opens; valid XLSX multipart `file` reaches Laravel; preview counts/errors; no save before confirmation; duplicate/unknown student/subject, formula/bad file; retry/replay; manual entry, correction reason/audit, disputed/revoked visibility, passed/failed/incomplete prerequisites. | A, N, I for equivalencies/legacy onboarding |
| Student Grades | Published versus verified historical results, term/CSV, watermark/read-only UI, unverified exclusion. | A, N |
| Document Requests | Single/cart requests, idempotent retry, appointments, status/rejection, claim code/email, access boundaries, no-show scheduler. | A, N |
| Physical Retrieval | Group tasks, location/cabinet snapshots, retrieve/return, missing location, authorization and concurrent actions. | A, N |
| Events/QR and Admin | QR attendance, moderator boundaries, notices, account provisioning/status/password reset, cross-role denial. | A, N |
| Platform/accessibility | Website/PWA install/update, Android real device, packaged Electron, keyboard/focus, contrast, responsive views, print/PDF/downloads, CSP/CORS consoles and offline/reconnect behavior. | N |

After UAT, record test account type, device/browser version, timestamp, evidence, observed result, and responsible Colegio de Montalban approver. Use synthetic or institutionally approved test records only.

## Actions requiring separate approval

- Staging, committing, pushing, merging, or deleting any existing workspace file; all three stashes remain untouched.
- Any production/legacy database read or write outside the operator-approved preflight, including migrations, seeders, partial-table repair, backup/restore, and historical data import.
- Cloudflare Pages/backend deployment, DNS/TLS/CORS/CSP changes, Google/Turnstile/SMTP configuration, Android/Electron release distribution.
- Institutional grading, curriculum equivalency, existing-student identity onboarding, and release UAT sign-off.
