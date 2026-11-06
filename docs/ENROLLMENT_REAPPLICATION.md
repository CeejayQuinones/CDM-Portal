# Enrollment reapplication after draft cancellation

## Lifecycle

Only an applicant-owned `draft` can transition to `cancelled`. The transition keeps its row, documents, term, owner, version, and audit event. During backfill, a cancelled row releases its blocking keys only when `submitted_at`, `reviewed_at`, `reviewed_by`, `finalized_at`, and `enrollment_id` are all null **and** an applicant-owned `enrollment.cancelled` audit event exists. This keeps manually altered or officially restricted historical rows blocking. New cancellations use the same state checks; the current service permits only the owner to cancel an unsubmitted draft and writes the audit event in the same transaction. A new application is a distinct draft and still requires the normal open term, Admission handoff or Student eligibility, and existing academic enrollment checks. Submitted, reviewed, rejected, approved, and enrolled records retain their blocking keys.

The status endpoint reports only a blocking application as `current_application`; paginated application history still includes cancelled rows. The Student/Guest view offers **Start New Enrollment Application** when eligible and opens the existing wizard after refreshing the status and history from the server.

## Migration and existing records

`2026_10_29_000001_allow_reapplication_after_cancelled_enrollment_draft.php` adds nullable `blocking_owner_user_id`, `blocking_student_id`, and `blocking_admission_applicant_id` columns. It backfills them from the original identity columns for every existing record except qualifying cancelled drafts. It creates unique indexes over blocking owner plus term, blocking Student plus term, and blocking Admission applicant, plus plain indexes on the original identity columns for foreign-key support, then removes the three corresponding old unique indexes. It does not delete or change any original identity, status, document, or audit data. The model maintains the blocking keys on future Eloquent writes; the database indexes reject concurrent duplicate active inserts. Creation also locks the term and returns a conflict for a duplicate index race.

Both SQLite and MariaDB allow multiple null values in unique indexes: [SQLite CREATE INDEX](https://www.sqlite.org/lang_createindex.html), [MariaDB NULL values](https://mariadb.com/docs/server/reference/data-types/null-values). This migration was validated only against disposable SQLite; it has **not** been run against development or hosted MariaDB.

## Deployment and rollback

Applying the migration to MariaDB needs separate approval, a verified database backup, and a maintenance window. MariaDB `ALTER TABLE` causes an [implicit commit](https://mariadb.com/docs/server/reference/sql-statements/transactions/sql-statements-that-cause-an-implicit-commit), so a failed multi-step migration needs inspection and a forward repair rather than assuming an automatic transaction rollback. Apply the migration before deploying code that writes the new columns; confirm the three replacement indexes and backfilled keys before enabling application traffic.

The migration `down()` first checks whether restoring the old constraints would conflict with retained cancelled and replacement applications. If it would, it stops without deleting data. Once any reapplication exists, stop new application creation during rollback, revert application code while retaining the new schema, and prepare a separately reviewed forward repair if needed. Older code does not populate the blocking keys, so it must not create applications against this schema without a repair plan. Do not run `down()` or delete application history to force the old indexes back.

## Validation on local `dev`

- Enrollment and Admission handoff Laravel suites on disposable SQLite `:memory:`: 53 passed, 763 assertions; after the final historical-audit backfill guard, the 24 directly affected tests passed with 337 assertions. Test setup applies the migration only to this disposable in-memory database.
- Full Vue suite: 76 test files passed.
- Website production, Android mobile-development, and Electron renderer builds: passed. The Website and Electron builds used an inert `https://build.invalid/api` build-time URL.
- Laravel Pint check and `git diff --check`: passed.
- Actual simultaneous MariaDB requests and rendered browser behavior remain manual UAT; no development MariaDB connection or migration was used.

## Manual verification

1. With an open term, start and cancel a draft as a Student and as an admitted Guest; verify the cancelled entry remains in history and a new draft opens the three-step wizard.
2. Verify a second draft is rejected, including two near-simultaneous requests; verify a submitted or approved application remains blocking.
3. Verify closed terms, ineligible Students, and invalid Admission handoffs cannot reapply.
4. Inspect the new and cancelled rows, audit events, and status endpoint; verify no Student identity is created for the admitted Guest until normal finalization.
