# CDM Portal maintainability audit

## Assessment and scope

**Needs cleanup.** The project has useful domain services, backend authorization, and substantial feature coverage. A broad rewrite would add risk. The highest-priority finding is a grade-aggregation defect in monitoring; retained client session state and Settings draft ownership also deserve focused follow-up before structural refactoring.

This review inspected frontend modules, components, layouts, services, stores, composables, utilities, routing and access control; backend routes, middleware, policies, controllers, services, representative models/resources/requests, mail, jobs, scheduled commands, Octane configuration, and tests. It was source review, not penetration testing, a dependency audit, production load testing, or a guarantee that every endpoint is defect-free. File sizes below are approximate pre-cleanup line counts; dense one-line code understates complexity.

Phase 1 findings and the small-batch plan were presented before any edits. Phase 2 only extracted an identical frontend error selector. The two existing untracked Registrar CSS files were preserved. No backend, UI, authorization, routing, schema, workflow, or AI behavior was changed.

Risk below means **risk of implementing the recommendation**, not a security severity score. “No” means deliberately not automated under this task's constraints.

## A. Critical maintainability issues

### 1. Monitoring grade aggregation skips initialized periods

- **Path:** `backend/app/Services/EarlyWarningService.php:103`, especially line 111.
- **Evidence:** `assessment()` initializes `Prelim`, `Midterm`, and `Final` to `null`, then tests `isset($periods[$grade->gradingPeriod?->period_name])`. That check is false for every initialized period. The loop therefore does not populate the grades or scores. The later empty-assessment path produces low risk and a stable-grades headline.
- **Why it matters:** Monitoring output and the academic summary supplied to AI can be misleading. Existing monitoring tests check access middleware and AI responses against a manually supplied assessment, rather than calculating an assessment from persisted grades.
- **Manual fix:** In a separately reviewed correctness change, use a key-existence check that accepts initialized null entries, after agreeing on missing/zero/unrecognized-period behavior. Do not change thresholds or AI prompts alongside it.
- **Coverage first:** Persist approved grades for recognized periods; assert subject periods, average, high/moderate/low boundaries, missing and zero grades, declining trends, unapproved-grade exclusion, and student/professor data scope. Include endpoint assertions for `/monitoring/my-risk` and `/monitoring/early-warnings`.
- **Risk:** high, because this changes monitoring/business output. **Automatic:** no.

### 2. Session teardown has multiple owners

- **Paths:** `CDM_Frontend/src/services/apiClient.js:49`, `src/stores/authStore.js`, `src/composables/useStepUpAuth.js:7`, `src/components/StepUpAuthModal.vue`.
- **Evidence:** The general 401 interceptor deletes local storage and changes the hash without clearing Pinia. `initialize()` is one-shot. Step-up state and a pending request closure are module-level; the modal has no unmount/session cancellation hook.
- **Why it matters:** Expiration can leave stale authenticated UI state, and a pending sensitive operation can outlive its initiating view/session. This is a client lifetime concern, not evidence of a backend authorization bypass.
- **Manual fix:** Define a single session teardown contract, clear retained stores and pending operations on identity changes, and prevent late verification responses from retrying an operation in another session. Avoid introducing an apiClient/store import cycle.
- **Coverage first:** 401 after successful initialization, logout while verification is open/in flight, login as a different user, cancellation, concurrent sensitive actions, and late `/me` responses.
- **Risk:** high. **Automatic:** no; authentication changes are explicitly excluded.

### 3. Settings drafts and saved baselines span unrelated sections

- **Path:** `CDM_Frontend/src/views/SettingsView.vue:36`–38.
- **Evidence:** `load()` assigns preference objects from the response into the form without copying them. Saving one section updates `saved` with the entire form, although only that section's payload was sent.
- **Why it matters:** A contact edit can be marked clean after saving only Profile; preference edits can also mutate the local loaded-response object before saving. Compressed functions make these ownership differences hard to review.
- **Manual fix:** Separate per-section draft/baseline ownership and copy nested preference values intentionally. Extract a Settings API adapter without changing endpoint contracts before extracting stateful composables.
- **Coverage first:** Edit two sections, save one, verify the other remains dirty; reset each section; fail a save; navigate during a save; switch users; upload/remove an avatar while settings fetches are pending.
- **Risk:** medium. **Automatic:** no; repairing this changes current behavior.

### 4. Behavioral coverage is weaker than source-shape coverage

- **Paths:** `CDM_Frontend/test/studentSettings.test.js`, `documentRequestMajorWorkflow.test.js`, `monitoringIntegration.test.js`; `backend/tests/Feature/EnsureMonitoringAccessTest.php`, `MonitoringAiHelpServiceTest.php`; `backend/phpunit.xml`.
- **Evidence:** Several frontend tests assert source substrings/regular expressions. Monitoring tests do not exercise persisted-grade assessment. Backend tests use SQLite in memory.
- **Why it matters:** Harmless extraction can break tests while interaction defects pass; SQLite does not establish MySQL row-lock behavior or persistent Octane worker isolation.
- **Manual fix:** Retain useful source/compile checks but add mounted behavior tests and targeted MySQL/worker integration suites before replacing them. Do not merely rewrite assertions to follow new source structure.
- **Coverage first:** Filters/pagination, keyboard dialog closure/focus return, failed/reordered responses, cross-role API access, two simultaneous capacity claims, and two users served sequentially by one worker.
- **Risk:** medium for test infrastructure replacement. **Automatic:** no wholesale replacement; isolated helper coverage is safe and was added.

## B. High-value cleanup opportunities

### 5. Oversized Vue views mix independent responsibilities

- **Paths:** `src/modules/student-management/PhysicalRecordsView.vue` (1,222 lines), `StudentProfileView.vue` (1,056), `StudentDocumentsView.vue` (819); `src/modules/document-request/StudentDocumentRequestView.vue` (1,058), `RegistrarDocumentRequestView.vue` (764), `RegistrarAppointmentsView.vue` (dense script/template/CSS).
- **Why it matters:** API orchestration, formatting, selection, modal focus, workflow mutation and layout share a scope. `StudentProfileView.vue:64` allows an older `loadProfile()` response to overwrite a newer route's record; other views already use request sequences, so protections are inconsistent.
- **Manual fix:** Extract pure domain presentation first. Then move one feature's fetch/selection lifecycle into a feature-local composable and extract its dialog, keeping event/prop contracts explicit. Do not create a universal CRUD/modal composable. No significant prop-drilling hotspot was established in this sample.
- **Coverage first:** Rapid route changes, selected-item reconciliation after refresh, close/unmount during requests, pagination/filter preservation, and focus return.
- **Risk:** medium. **Automatic:** no for stateful splits; only the pure extraction described below was performed.

### 6. Registrar controllers own queries, response presentation and mutations

- **Paths:** `backend/app/Http/Controllers/Api/RegistrarDocumentRequestController.php` (678 lines), `RegistrarCabinetController.php` (306), `RegistrarStudentDocumentController.php` (217).
- **Why it matters:** History/date/search/activity query logic shares a controller with appointment transitions. Cabinet creation, slot-capacity checks and record assignment transactions are controller-owned. Moving these indiscriminately can change response shape, transaction scope, or lock order.
- **Manual fix:** First extract read-only query/presentation objects; separately extract transactional cabinet/standalone-appointment actions into focused domain services. Keep validation in Form Requests, response shape in Resources, and authorization at current boundaries until explicitly reviewed.
- **Coverage first:** Exact response contracts, timezone/day boundaries, pagination ordering, full/closed dates, assignment capacity, concurrent updates, rollback, and audit fields.
- **Risk:** high. **Automatic:** no.

### 7. Legacy workflow surfaces and scattered status strings can drift

- **Paths:** `src/modules/document-request/StudentDocumentRequestView.vue` (`canBookSelectedRequest` returns false while booking state/helpers remain), `documentRequestService.js` (legacy availability endpoint), `src/services/offline/offlineApi.js`; `backend/app/Services/DocumentRequestWorkflowService.php`, `app/Console/Commands/CancelMissedDocumentAppointments.php`, request/appointment models/controllers.
- **Why it matters:** Current workflow, historical aliases and offline simulation are different concepts but reuse status strings. Dead-looking booking code may still have dependencies. A frontend display mapping must not become a business transition table.
- **Manual fix:** Inventory call sites and reachable routes, add scenario parity tests, then remove only proven-unused legacy branches. Introduce narrowly scoped domain constants/enums only with serialization/cast compatibility tests.
- **Coverage first:** Pending → assigned → approved → verified → completed/cancelled; rejection; no-show cancellation; resend; terminal immutability; online/offline parity; historical aliases.
- **Risk:** high. **Automatic:** no.

### 8. Broad settings/monitoring reads and duplicated settings fetches

- **Paths:** `backend/app/Http/Controllers/Api/StudentSettingsController.php:70`, `backend/app/Services/EarlyWarningService.php:13`; `src/composables/useStudentTheme.js`, `src/views/SettingsView.vue`.
- **Why it matters:** The settings controller loads documents, requests, appointments and enrollment relationships even for small updates. `fresh()` followed by relationship access in the payload can reload relations lazily. Layout and Settings independently fetch the same endpoint. Monitoring eagerly materializes all matching students and their nested grade data.
- **Manual fix:** Measure query counts/payload sizes first; establish a user-scoped settings fetch owner and narrower query/aggregate projections. A bounded monitoring API needs an explicit contract decision. `StudentDocumentResource` calls model helpers that can `loadMissing` document types; keep collection callers eager-loaded to prevent N+1 regressions.
- **Coverage first:** Query-count budgets for representative collections, no lazy loads during serialization, cold/warm settings loads, failed refresh and identity changes. Preserve output ordering and totals.
- **Risk:** medium. **Automatic:** no. These are observed query shapes and risks, not measured production performance failures.

### 9. Failure boundaries hide different operational failures

- **Paths:** `backend/app/Http/Controllers/Api/RegistrarDocumentRequestController.php:359`, `StudentSettingsController.php:46`, `backend/app/Jobs/AnalyzeStudentDocument.php`; `MonitoringController.php::aiHelp`.
- **Why it matters:** Every appointment `QueryException` becomes a slot-conflict 409. Avatar replacement deletes the old public file before storing the replacement. The analysis job catches any throwable and stores a generic failure; unexpected failures have limited diagnostic context. Monitoring currently relies on service-created `RuntimeException` messages being public-safe.
- **Manual fix:** Classify only the intended unique-constraint conflict; stage the new avatar and clean it up on database failure before deleting the old file; add safe structured diagnostics without document contents/tokens; consider a dedicated public-safe AI exception contract later.
- **Coverage first:** Storage write failure, database update failure, unrelated database exceptions, unknown analyzer failures, and provider/internal-message redaction. Keep user-facing message contracts explicit.
- **Risk:** high. **Automatic:** no.

### 10. Stylesheet ownership and cascade coupling

- **Paths:** `src/modules/document-request/documentRequest.css` (3,640 lines), `src/assets/styles/student-portal.css`, `student-theme.css`, existing untracked `registrar-components.css`/`registrar-tokens.css`, importing Vue views.
- **Why it matters:** A large mixed Student/Registrar stylesheet is included as scoped CSS by multiple components. Generated scopes duplicate style payloads, while local and theme overrides make precedence difficult to reason about. The Registrar draft files were untracked and not imported by the inspected layout at this turn's start; audit cleanup must not silently activate a design change.
- **Manual fix:** Record current screenshots, establish shared primitive versus feature ownership, and migrate one component at a time. Decide separately how to finish/track the pending Registrar theme. Keep current appearance and mobile layouts unchanged during extraction.
- **Coverage first:** Student light/dark/system, Registrar lists/filters/dialogs, mobile cards, keyboard focus, disabled/selected states, and bundle CSS size.
- **Risk:** medium. **Automatic:** no in this task.

### Safe cleanup performed: identical request-form error selection

- **Paths:** `src/modules/document-request/StudentDocumentRequestView.vue`, `RegistrarAppointmentsView.vue`, new `documentRequestErrors.js`.
- **Problem:** Both views implement the same first-validation-message → response-message → fixed-fallback selection.
- **Fix:** Extracted `documentRequestErrorMessage`, imported as the existing local `requestError` name. No call sites, messages, request timing, or error precedence changed. Other error handlers deliberately retain their different policies, including Settings' joined field messages and History's generic messages.
- **Risk:** low. **Automatic:** yes; completed with focused characterization coverage.
- **Dates/statuses:** Similar-looking formatters were not unified: local versus Manila dates, date-only timestamps, missing-value labels, and historical aliases differ. Avatar URL resolution is already centralized; no duplicate abstraction was introduced.

## C. Nice-to-have cleanup

| Paths | Problem / why it matters | Recommended next step and protection | Risk / automatic? |
| --- | --- | --- | --- |
| `backend/tests/Feature/*`, especially physical records, dashboard, bulk updates and document tests | Repeated `createStudent`/`userWithRole` fixture code can drift. | Extract small factory states one suite at a time; retain each fixture's enrollment/role defaults and run the affected suites. | Low / yes later, after comparing differences. |
| `backend/app/Services/Ai/Analyzers/GeminiDocumentAnalyzer.php` (835 lines) | Transport, prompts, parsing, normalization, provider diagnostics and domain checks share a class. | Isolate pure parser/normalizer functions with malformed JSON, response type, confidence, document mismatch and redaction fixtures before moving HTTP code. | High / no now. |
| Frontend role comparisons; `config/accessControl.js` | Some literal role names duplicate existing constants. | Use current constants opportunistically; do not alter role membership. Assert route/menu parity. | Low for literal-only substitutions / yes later. |

## D. Existing strengths and security/data-boundary review

- `routes/api.php` uses Sanctum plus role middleware; student request policies enforce ownership, and monitoring checks student/professor scope in the backend. An arbitrary route ID is not, by itself, an authorization defect. Keep these boundaries during extraction.
- Form Requests and validated payloads constrain mutations; the inspected main mutation paths do not blindly pass the complete HTTP body into models. Models have explicit fillable/cast/relationship definitions. There is no justification here for a schema rewrite.
- `DocumentRequestWorkflowService` centralizes the current claim workflow, uses transactions/locks, and invalidates claim secrets on terminal states. `DocumentRequest` hides code hash/lookup fields; approval mail is a deliberate delivery boundary. Tests cover claim secrecy, invalid transitions and mail failure.
- `RegistrarStudentDocumentController` serves managed private files with path checks and private/no-store responses; uploads have MIME/size validation. Its new-file-first rollback handling is stronger than the avatar path, which should be reviewed separately.
- `MonitoringAiHelpService` sends public-safe failure messages and logs structured metadata. The document analyzer limits local diagnostics and redacts provider strings; AI API resources omit raw extracted payloads. Redaction is useful defense, not proof every possible provider echo is safe. Do not add raw provider bodies to logs.
- `apiAssetUrl.js`, frontend access-control config, domain navigation/presentation modules, PaginationControls, student avatar store, and theme revision guards are existing reusable owners. Preserve them rather than inventing equivalents.
- History and capacity tests run real component handlers with mocked APIs; backend feature tests cover authorization, requests, files, records and AI error cases. These are stronger foundations than the source-only frontend checks.
- Octane configuration retains the framework request preparation listeners, and the inspected provider binds analyzers without retaining request/user state as a singleton. No worker leak was established by this audit; persistent-worker isolation still needs runtime coverage.

## E. Deliberately unchanged

Monitoring calculations, AI prompts/provider behavior, authentication, authorization, routing, Pinia, workflow states, API contracts, database schema/migrations, UI design, scheduling, locks, and Octane/FrankenPHP configuration. No production data, accounts, secrets, or deployment settings were modified. No commits or pushes were made.

## Prioritized small batches

1. **Completed:** domain-local error selector and focused behavior tests; no broad error framework.
2. **Next, separately approved correctness work:** add persisted-grade assessment tests and fix finding 1. Test session lifetime and Settings drafts before addressing findings 2–3. These are behavior fixes, not automatic cleanup.
3. **Frontend safety coverage:** mounted Settings, student-profile stale responses, workflow dialogs, filter/pagination and offline contract scenarios. Keep existing tests until replacements protect the same contracts.
4. **Frontend extraction:** one Settings section, Registrar dialog or physical-records concern per change. Put stateful feature behavior in local composables, API transport in module services, pure display functions in domain presentation files, and durable user/session state in existing stores. Keep shared components presentation-focused and config declarative.
5. **Backend extraction:** read queries/resources first; cabinet/standalone-appointment transactions later. Require endpoint characterization and MySQL concurrency tests; preserve lock order, validation and error contracts. Improve failure handling in separate changes.
6. **Cleanup after safety net:** test factory states, measured query/payload reductions, proven-unused legacy paths, and a separate stylesheet-ownership migration. Do not combine these into one refactor.

## Actual safe-pass files and validation

- Added `CDM_Frontend/src/modules/document-request/documentRequestErrors.js`.
- Updated only imports/removed duplicate helper definitions in `StudentDocumentRequestView.vue` and `RegistrarAppointmentsView.vue`.
- Added `CDM_Frontend/test/documentRequestErrors.test.js` covering validation precedence, empty field errors, response messages and network/missing-response fallbacks.
- Added this audit report. No component, store, controller or service architecture was split; only one pure helper was extracted.
- Frontend: `npm test` passed (11 test files); `npm run build` passed.
- Backend: `php artisan test` passed (149 tests, 1,023 assertions); `vendor/bin/pint --test` passed. No backend code was changed.
- Repository: `git diff --check` passed, including the completed report.

Passing the existing suite does not resolve the documented monitoring coverage gap. The next priority is a narrowly scoped persisted-grade assessment test and reviewed correctness fix, not a broader redesign.
