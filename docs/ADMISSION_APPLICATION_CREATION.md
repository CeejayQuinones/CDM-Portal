# Admission application creation — Step 7

Only controlled Guest identity creation is implemented. No schema changes,
migrations, imports, cycle configuration UI, exam engine, results, recommendations,
Registrar workflow or academic Student conversion are included. Existing academic,
Monitoring, Document Request and authentication behavior remains unchanged.

## HTTP contract and authorization

- `GET /api/admission/me`: unchanged safe identity response and ownership rules.
- `GET /api/admission/applications/availability`: active Guest/Student self-service
  only; returns `data.allowed` and a safe reason. Students always get false.
- `POST /api/admission/applications`: existing Sanctum authentication; active Guest
  only, existing profile required, and no academic Student record. All supplied
  payload fields are ignored: owner, cycle, applicant number and status are server-owned.
  Professor, Student, Registrar and Admin cannot create. Persisted roles/account
  status are refreshed; client role state is not authoritative.

POST uses the existing Laravel `throttle:5,1` pattern (five attempts per minute,
including failed requests, with the portal's existing authenticated throttle key).
No new limiter subsystem or authentication mechanism is introduced.

Success is HTTP 201 with the same whitelisted identity projection as GET /me.
Duplicate same-cycle or active cross-cycle application returns HTTP 409 and
`application_exists`. Missing profile or unavailable cycle returns 422; forbidden
creation returns 403; throttle returns 429; unexpected/storage/audit failures return
503 with a generic message. Custom responses use private/no-store caching. SQL,
exception traces, audit metadata, source identifiers and unrelated profile fields
are never part of creation responses, including when server debug is enabled.

## Cycle selection and transaction

The service selects exactly one cycle with status `open` and
`opens_at <= server now < closes_at`. No eligible cycle is `no_open_cycle`;
multiple eligible cycles fail closed as `cycle_unavailable`. No arbitrary cycle ID,
new status, or implicit cycle is accepted. Availability is advisory; POST repeats
checks under locks. The existing service rechecks the selected cycle's dates after
locking it, so closing an intake cannot authorize creation using a stale UI check.

`createForOpenCycle` locks User, selects/locks current eligible cycles in ID order,
then calls the existing `create` service in the same outer transaction. Existing
profile, same-cycle uniqueness, cross-cycle active-case rules, server UUID generation,
collision retries and audit insertion are retained. Applicant and
`admission.identity_created` commit together; audit failure rolls both back.
Same-user requests serialize and the loser receives a clean conflict. No academic
Student, account, profile or extra audit event is created on duplicate requests.

## Frontend

The existing Admission home loads identity and, only for a Guest without an
application, checks availability. Eligible Guests see **Start Admission Application**.
A small confirmation dialog explains the generated number and saved draft. Cancel
performs no write. The confirm handler synchronously guards repeated submissions;
controls disable while pending. Success renders the returned identity immediately.
A duplicate refreshes existing identity. Permission, throttle, unavailable-cycle
and generic failures display local safe messages, never raw server messages.
Account changes/unmount invalidate pending read and creation responses.

Students have no creation button. Existing sidebar, layout and role guards remain.
Exam, result and recommendation routes remain placeholders; creation starts no exam.
The UI deliberately does not offer reapplication from an existing historical case.

## Verification and operational limits

The focused tests exercise HTTP ownership, roles, stale suspended accounts, profile
reuse, server fields, response allowlist, audit rollback, missing storage, cycle
windows/ambiguity, duplicate/cross-cycle blocking and throttling. Mounted Vue tests
exercise confirmation/cancel, repeated submit, immediate success, failure messages,
duplicate refresh, account-switch safety and retained placeholders.

`php scripts/verify-admission-concurrency.php --run-with-fixtures` now runs three
HTTP-kernel endpoint races before its existing service checks. Sanctum's test helper
supplies synthetic authenticated identities; routing, middleware, controller and
real database transactions execute normally. This is not a browser/network or token
issuance test. Endpoint races skip if a real open cycle exists; real configuration
is never changed. Exact-ID fixture cleanup and before/after database fingerprints
remain mandatory. Synthetic cycles are briefly visible during this local diagnostic;
run it only in a quiet non-production environment.

Verified locally on MariaDB 10.4.32, REPEATABLE-READ, through Laravel's mysql driver:
three endpoint races each returned one 201 and one 409 with overlapping User lock
waits confirmed. Existing service races, distinct allocation, forced number collision,
retry exhaustion, database uniqueness and post-audit rollback passed. Twelve fixture
applicants had twelve matching events. Cleanup passed and existing schema/data
fingerprints matched. This does not prove Oracle MySQL 8 behavior or load capacity.

Deploy against already-applied Step 3 and Step 6 schema; no migration is required.
An authorized operator must have configured one valid open cycle before creation is
available. This step does not create production cycles or applicants. Keep the portal
clock/timezone and shared throttle cache configured correctly. Ambiguous cycles deny
creation until configuration is corrected. Future cycle-management mutations must
honor the same transaction/locking conventions. Revert code to disable the endpoint;
preserve any real applicants and audit evidence instead of deleting tables or rows.

Recommended Step 8: minimal Admin-only admission-cycle management with date validation,
serialized active-cycle rules and audit-backed changes. Keep exam implementation in
a separately scoped phase.

## Changed files

- backend/routes/api.php
- backend/app/Http/Controllers/Api/Admission/AdmissionApplicationController.php
- backend/app/Http/Controllers/Api/Admission/AdmissionIdentityController.php
- backend/app/Services/Admission/AdmissionIdentityService.php
- backend/tests/Feature/AdmissionApplicationCreationTest.php
- backend/scripts/verify-admission-concurrency.php
- CDM_Frontend/src/modules/admission/AdmissionView.vue
- CDM_Frontend/src/modules/admission/services/admissionService.js
- CDM_Frontend/test/admissionIntegration.test.js
- docs/ADMISSION_APPLICATION_CREATION.md
