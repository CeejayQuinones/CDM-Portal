# Professor Gradesheet Excel Import and Export

## Approved implementation boundary

This implementation covers the complete Website Professor Grade Entry workflow: protected `.xlsx` export, non-writing validation preview, explicit confirmation, and transactional application of the exact reviewed score changes as grading drafts. Applying an import never submits a grade sheet to the Registrar.

The existing direct score-entry workflow, grading formulas, assessment management, criteria, submission workflow, authorization rules, and database schema remain unchanged.

Pre-change recovery checkpoint: `/tmp/cdm-gradesheet-excel.o82l7s`

- Tracked working-tree patch SHA-256: `20dbcb7b60aec65d1a8f80bee7e7a6e1bdaadf5d6b60a86338a16336a6996f04`
- Untracked-files archive SHA-256: `996e92192bad1fa8afd7d05241a33fab006eb84256fb05e601809bfca5cfc439`
- Branch `dev`, HEAD `bf7a6893b525662e1edb56e9ac1e1f9d1e1ad828`
- All three pre-existing Git stashes remain untouched.

Phase 2 recovery checkpoint: `/tmp/cdm-excel-apply.scuESY`

- Tracked working-tree patch SHA-256: `0a2f1884e2300236d253ecbe7c6d993618c557a1ee82f62a249b06af3dae89c3`
- Untracked-files archive SHA-256: `155cf1996cc33bb0d57ff2f4d2653b79584b089762f47baf65853b65f10c1d9e`
- Branch and HEAD were unchanged before Phase 2, and all existing stashes were preserved.

Resume-validation recovery checkpoint: `/tmp/cdm-excel-phase2-resume.clHckC`

- Tracked working-tree patch SHA-256: `94b75af9826ae8e05ec7fa2caccafd4982069a726bcda7e38129e2cb4ac7c1c7`
- Untracked-files archive SHA-256: `19fecbb622f3ee1f469190cfa0018d6f7eecc02f5509e7d798621214626c9e57`
- Created after the earlier temporary checkpoint had been cleaned up; branch `dev`, HEAD, and all three existing Git stashes remained unchanged.

Phase 3 pre-change recovery checkpoint: `/tmp/cdm-excel-phase3-prechange.HQxxJf`

- Tracked working-tree patch SHA-256: `cea66f12b679b8e705fc20d7d951bc2dd4f18a52416b98ef897f7fe5ecca205c`
- Untracked-files archive SHA-256: `3426d0226512ec35249c68a2594dd3858e33e6deb2b0eec483e5993fc96200f4`
- Captured before the unified Website workflow was changed. Branch `dev`, HEAD, and all three existing Git stashes were preserved.

## Phase 1 audit and implementation plan

The existing grading workspace supplies stable `grade_sheet`, `section_subject`, `enrollment_subject`, Student, assessment, score, and version identifiers. Score updates are currently sent one assessment at a time to `PUT /api/grading/sheets/{sheet}/scores`; `GradingService::saveScores()` enforces Professor ownership, sheet status, grading windows, roster membership, maximum scores, and optimistic score versions. The frontend's multi-assessment Save operation calls that endpoint sequentially and can partially succeed.

`phpoffice/phpspreadsheet` has a `^5.2` Composer constraint and installed version 5.10.0. No additional dependency is required. The approved seal source is `CDM_Frontend/src/assets/styles/images/cdm-seal.png`.

The implementation plan is:

1. Add read-only Professor routes for workbook export and preview.
2. Reuse the existing Professor-role, web-platform, assignment ownership, roster, schedule, and calculation infrastructure.
3. Generate one editable Grade Entry sheet plus one signed, protected, very-hidden metadata sheet.
4. Parse only `.xlsx` files within the configured size limit and reject formulas, unexpected sheets, invalid identifiers, changed template structure, and tampered metadata.
5. Reconcile imported cells against current server values and exported baseline versions without writing any grade data.
6. Present blocking issues and proposed old/new values in a Website-only review dialog.
7. Issue a short-lived proposal only for a clean preview, require explicit confirmation, and apply it using one locked database transaction.

## Export workbook structure

The Website Professor Grade Entry toolbar exposes one `Edit in Excel` action. Step 1 of that guided workflow downloads the Midterm or Finals workbook through the existing export endpoint. The endpoint rechecks the authenticated Professor, Website client, grade-sheet ownership, and current assignment before reading data.

The generated `.xlsx` contains exactly two sheets:

### `Grade Entry`

- Colegio de Montalban heading and the approved seal from `CDM_Frontend/src/assets/styles/images/cdm-seal.png`.
- Academic year, semester, subject, section, and current grading period.
- Three visible identity columns: No., Student Number, and Student Name.
- One editable column for every active assessment in the selected period, grouped by its authorized category.
- Maximum-score guidance beneath every assessment label.
- Static, protected Raw Total, Midterm, Finals, Final Grade, Equivalent, and Remarks values. These values are informational and are never trusted on import.
- One hidden enrollment-subject identifier column used to support harmless Student-row reordering.

### `_CDM_Metadata`

- Template version `CDM-PROFESSOR-GRADESHEET-1`.
- Grade sheet, class assignment, Professor, academic year, semester, grading period, and sheet-version identifiers.
- Stable Student and enrollment-subject identifiers.
- Stable assessment identifiers, versions, labels, categories, maximum scores, statuses, and expected column positions.
- Existing score values and optimistic-lock versions at export time.
- An HMAC-SHA-256 signature over the complete metadata payload using the application key.

The metadata sheet is protected and `veryHidden`. Protection is usability guidance; authorization and integrity are enforced again by Laravel.

## Editable and protected fields

Only assessment score cells are unlocked. They have a pale-green fill, numeric validation, and a convenience range from zero through the assessment maximum.

The following are protected where Excel permits:

- Student and enrollment identifiers.
- Student names and numbers.
- Assessment labels, categories, identifiers, and maximums.
- Grade-sheet context.
- Raw totals and calculated results.
- Metadata and its signature.

Laravel does not trust workbook protection, Excel validation, static calculated results, or any client-provided identity text.

Blank-cell behavior is explicit:

- Blank score cell: leave the current server score unchanged.
- Numeric zero: propose an explicit score of zero.
- There is no clearing/deletion operation in this version.
- Formula, text, negative, non-finite, and over-maximum score cells are invalid.

## Excel protection editability repair

The initial exporter correctly assigned `locked="false"` to score-cell styles, but it also called PhpSpreadsheet 5.10's `setSelectUnlockedCells(true)`. In PhpSpreadsheet and OOXML this flag means **prevent selection of unlocked cells**, rather than permit it. With both selection-protection flags enabled, Excel could leave focus on a protected cell and report that the selected cell could not be changed even though score-cell styles were nominally unlocked. The earlier test only reloaded the high-level style object and did not inspect the serialized XLSX style record, so it could not prove Excel's effective behavior.

The repaired exporter now:

- Applies borders, alignment, fills, and all other bulk presentation styles first.
- Applies `Protection::PROTECTION_UNPROTECTED` to the complete real Student-by-assessment range as the final score-cell style operation.
- Serializes `selectUnlockedCells="0"` so Excel permits score-cell selection.
- Serializes `selectLockedCells="1"` so protected identity and calculated cells are excluded from ordinary Tab/Enter navigation.
- Selects the first editable assessment cell when the workbook opens.
- Keeps every real score cell pale green, including blank and zero-valued cells.
- Keeps identity, headings, maximums, calculated results, and very-hidden signed metadata protected.

Automated tests open the final ZIP package and inspect `xl/worksheets/sheet1.xml` plus `xl/styles.xml`, rather than relying only on PhpSpreadsheet's in-memory API. The emitted score-cell XF records contain `applyProtection="true"` and `<protection locked="false"/>`; protected fields do not use an unlocked XF. Coverage includes the first and last Midterm score cells, populated/blank/zero cells, Finals score cells, Student identity fields, assessment headings and maximums, calculated result columns, worksheet selection protection, and the protected very-hidden metadata sheet.

The round-trip test edits and saves a legitimate score cell, submits it to the existing preview endpoint, verifies zero and blank semantics, verifies tampered identity rejection, and confirms no grade records are written. Microsoft Excel itself is not available in the local Linux environment, so final behavior in Windows Excel remains a required manual UAT step.

## Import mapping strategy

Step 3 of `Edit in Excel` accepts only `.xlsx` files up to 5 MB through either the accessible file picker or drag and drop. The hidden enrollment-subject identifier follows its Student row when the row is sorted, so row order is not used as identity. Assessment columns are mapped from signed metadata and must remain in their exported order.

Preview rebuilds the current authorized workspace and reconciles the workbook against:

- Current class, term, Professor, period, and sheet version.
- Current official roster.
- Current active assessment set and assessment versions.
- Current assessment label, category, maximum, status, and expected column.
- Current score values and versions.

Missing, duplicate, unknown, or altered identities are blocking. Missing, reordered, renamed, or extra columns are blocking. Extra populated rows without a recognized stable identifier are blocking.

## Validation and security controls

The preview operation performs these controls without writing grades:

- Existing Sanctum authentication and active-Professor authorization.
- Exact Website-client enforcement for both export and preview.
- Grade-sheet ownership and current class-assignment verification.
- Laravel upload size and `.xlsx` type validation.
- Exact two-sheet structure and supported template version.
- Signed metadata verification using constant-time comparison.
- Rejection of workbook formulas, hyperlinks, and defined names.
- Roster and assessment identifier uniqueness and membership checks.
- Protected visible identity/header cross-checks.
- Numeric type, finite value, non-negative value, and assessment-maximum checks.
- Draft/Returned sheet status and current grading-window checks.
- Baseline-versus-current score version and value conflict detection.
- Assessment-definition and grade-sheet version conflict detection.

The file is parsed with PhpSpreadsheet's existing `.xlsx` reader. No formulas or calculated workbook values are evaluated or used as authoritative data.

## Unified Edit in Excel workflow

The Website Professor toolbar uses one spreadsheet-icon action instead of separate Export and Import buttons. It opens one responsive, five-step dialog for the active subject, section, and grading period:

1. **Download Gradesheet** uses the existing export API and identifies the current class and period.
2. **Edit in Microsoft Excel** explains protected cells, blank-versus-zero behavior, the required save step, and that there is no automatic synchronization.
3. **Upload Edited File** accepts `.xlsx` files from a keyboard-operable picker or drag and drop, shows the selected filename and validation progress, and calls the existing preview API.
4. **Review Changes** displays the real preview response, including:

- Recognized Students.
- Proposed score changes.
- Unchanged scores.
- Invalid cells.
- Missing and unknown identifiers.
- Concurrent conflicts.
- Blocking errors and cell locations.
- Student, assessment, existing value, imported value, and Ready/Conflict state for each proposed change.

5. **Apply Changes** restates the exact proposed change count and the draft-only boundary before calling the existing transactional apply endpoint.

The state transitions are `download -> edit guidance -> upload -> review -> apply`. Back navigation remains available, completed steps can be revisited, and `Start over` clears only the selected local file and preview. A preview error stays on Upload; it never advances automatically. A blocking preview stays on Review and cannot advance to Apply. Closing after selecting a file, downloading, or receiving a preview requires confirmation and explicitly states that no grades have changed.

The upload endpoint never writes grades. A clean preview containing at least one change receives a signed, opaque, account-and-sheet-bound proposal token that expires after 10 minutes. Invalid previews and previews with no changes receive no applicable proposal. The dialog tracks the expiry time and disables Apply for an expired proposal.

`Confirm & Apply Score Drafts` is enabled only when the proposal is applicable and unexpired, the grading period remains editable, no blocking errors exist, and the browser has no unsaved score or column changes. A separate browser confirmation says: `You are about to save X score changes as grading drafts. This will not submit grades to the Registrar.` The busy state prevents repeated UI submissions.

After a confirmed server success, Grade Entry reloads the authoritative workspace, calculated results, and readiness data while keeping the Professor on the page. The dialog closes and clears its local file/preview state only after the apply response. If apply fails, no success message is shown; stale, expired, or invalid proposals are disabled and require a new preview. If the server saves successfully but the subsequent refresh fails, the UI truthfully reports that the scores were saved and instructs the Professor to reload the class.

Pending browser score edits or column edits are never silently discarded. The dialog shows a warning and disables download, upload, and apply until the Professor closes the dialog and intentionally saves or discards those edits. Direct browser score entry, Save changes, and Grade Submission remain separate workflows.

The dialog supports Escape with the same close warning, traps Tab focus while open, returns focus to `Edit in Excel` after closing, moves focus to each step heading, uses live loading status, and keeps review tables horizontally scrollable. At narrow widths the step titles collapse while numbered progress, touch targets, context, and actions remain available. All colors use the existing semantic Light/Dark/System tokens, and reduced-motion mode disables the loading spinner animation.

## Transaction and concurrency design

The existing browser `saveScores` endpoint remains transactional per assessment. Phase 2 does not reuse the frontend's multi-request loop because that could partially apply a cross-assessment workbook.

Preview creates a cryptographically signed opaque token bound to the authenticated user and grade sheet. The configured cache stores only the normalized server-validated proposal for 10 minutes; the raw workbook is not retained. The proposal contains the workbook SHA-256, term and schedule snapshot, roster identifiers, assessment definitions and versions, exact normalized changes, and baseline score values/versions. The apply endpoint accepts only this token, period, and explicit confirmation—not browser-submitted Student IDs or scores.

Apply acquires a cache lock for the proposal and then opens one database transaction. Within it, the service locks and revalidates the grade sheet, class assignment, grading schedule, active assessments, roster, and scores. It rechecks Professor ownership, term and period, draft/returned status, grading-window rules, assessment status/version/maximum, roster membership, numeric ranges, and every baseline score value/version before writing anything. Normal browser score saving now obtains the same grade-sheet lock before assessment and score locks, serializing it safely with Excel apply.

Only after all validation succeeds are every intended score and the audit records written. Any validation error, concurrency conflict, or database exception rolls back the complete import. Blank cells never enter the proposal; numeric zero remains an explicit change.

Successful proposals are consumed. A short-lived cached result makes duplicate clicks and immediate network retries idempotent: they return the original operation result with `replayed: true` and perform no score or audit writes. Expired, malformed, mismatched, or stale proposals are rejected. A process interrupted after database commit but before proposal consumption is still protected by the newly incremented score versions on retry.

## Required backend changes

Implemented read-only additions:

- `GET /api/grading/sheets/{gradeSheet}/workbook?period=midterm|finals`
- `POST /api/grading/sheets/{gradeSheet}/workbook/preview`
- Read-only owned-workspace access in `GradingService`.
- `ProfessorGradesheetWorkbookService` for export, parsing, integrity validation, and reconciliation.

Implemented Phase 2 additions:

- `POST /api/grading/sheets/{gradeSheet}/workbook/apply`
- `ProfessorGradesheetImportService` for proposal issuance, apply-time revalidation, locking, atomic writes, and idempotency.
- Ten-minute signed proposal and consumed-result cache lifecycle.
- Existing per-score audit actions with import source and operation ID.
- Aggregate `scores.excel_import_applied` audit event containing actor linkage, class/term/period identifiers, counts, assessment IDs, workbook hash, and operation ID.

No database migration is required. The implementation uses the project's existing configured cache and `grade_audit_events` infrastructure. It does not store raw workbook bytes or Student names in audit metadata.

## Files changed for this feature

Added:

- `backend/app/Services/Grading/ProfessorGradesheetWorkbookService.php`
- `backend/app/Services/Grading/ProfessorGradesheetImportService.php`
- `backend/tests/Feature/ProfessorGradesheetWorkbookTest.php`
- `CDM_Frontend/test/professorGradesheetExcel.test.js`
- `docs/PROFESSOR_GRADESHEET_EXCEL_IMPORT_EXPORT.md`

Modified:

- `backend/app/Services/Grading/GradingService.php`
- `backend/app/Http/Controllers/Api/GradingController.php`
- `backend/routes/api.php`
- `CDM_Frontend/src/modules/grading/gradingService.js`
- `CDM_Frontend/src/modules/grading/GradingView.vue`
- `CDM_Frontend/src/modules/grading/grading.css`
- `CDM_Frontend/test/gradeEntryRenderingFix.test.js`

Phase 3 modified only:

- `CDM_Frontend/src/modules/grading/GradingView.vue`
- `CDM_Frontend/src/modules/grading/grading.css`
- `CDM_Frontend/test/professorGradesheetExcel.test.js`
- `docs/PROFESSOR_GRADESHEET_EXCEL_IMPORT_EXPORT.md`

No backend route, controller, workbook parser, transactional import service, grading rule, database schema, Android view, or Electron view was changed for Phase 3.

## Automated test results

- Focused workbook and transactional-apply tests: 15 passed, 305 assertions.
- Relevant Laravel grading suites: 50 passed, 673 assertions.
- Full Laravel suite: 475 passed, 4,532 assertions using command-scoped `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, and `CACHE_STORE=array`; the development MariaDB database was not accessed.
- Full frontend suite: 66 test files passed, 0 failed.
- Website production build: passed with command-scoped `VITE_API_BASE_URL=https://example.invalid/api`; no `.env` file changed.
- Android development build: passed, confirming Website-only UI isolation.
- Electron renderer build: passed, confirming Website-only UI isolation.
- Full Laravel Pint check: passed.
- `git diff --check`: passed with no whitespace errors.

Phase 3 validation on 2026-10-28:

- Focused unified-workflow frontend contract checks: 7 subtests passed.
- Full frontend suite: 66 test files passed, 0 failed.
- Focused Laravel workbook/export/preview/apply suite: 15 passed, 305 assertions using in-memory SQLite and array cache.
- Website production build: passed with a command-scoped inert HTTPS API placeholder; no `.env` file changed.
- Android development build: passed; the unified action remains inaccessible outside the Website branch.
- Electron renderer build: passed; the unified action remains inaccessible outside the Website branch.
- `git diff --check`: passed.

Coverage includes final serialized XF and worksheet-protection XML, Midterm and Finals editability, first and last score cells, populated/blank/zero score cells, protected identity and result cells, export structure and logo, protected metadata, stable identity mapping after row sorting, preview-only grade behavior, valid atomic apply, wrong Professor/sheet/platform denial, invalid score rollback, schedule and submission locks, changed assessment/roster/score conflicts, forced database failure rollback, audit metadata, proposal expiry/tampering, and replay-safe idempotency.

## Remaining limitations and deployment note

- Proposals intentionally expire after 10 minutes; an expired or stale proposal requires another upload and preview.
- Excel apply is Website-only and is not presented to Android or Electron clients.
- The cache store must support atomic locks. Local development uses the configured cache without schema changes. Any future horizontally scaled deployment must use a cache shared by all application instances, such as the project's supported Redis or database cache configuration, before enabling this workflow there.
- The raw workbook is not retained after preview, so operational auditing records its SHA-256 and normalized change counts rather than the uploaded file or Student names.
- Apply saves grading drafts only. Grade Submission remains an explicit, separate Professor action governed by the existing readiness and Registrar workflow.

## Manual UAT

1. Sign in to the Website as a Professor, open an assigned class in Grade Entry, select Midterm, and confirm the toolbar has one `Edit in Excel` action with no separate Export/Import actions.
2. Open `Edit in Excel`; verify the subject, section, and Midterm context, five-step progress indicator, close action, and initial keyboard focus.
3. Download a **new** workbook in Step 1, continue through Step 2, and verify the blank-versus-zero and save instructions are clear.
4. Open the workbook in Microsoft Excel, select a pale-green score cell, type a different valid score, and press Enter without unprotecting the sheet.
5. Save the workbook as `.xlsx`, upload it by file picker, and verify Step 4 recognizes the exact old/new score change.
6. Verify Enter and Space on the upload area open the file picker, then separately verify dragging and dropping an `.xlsx` file is accepted.
7. Confirm that a Student Name cell cannot be edited and verify the filename, seal, class context, roster, categories, assessments, saved scores, and protected calculated columns.
8. Repeat for Finals and confirm only Finals assessments are editable.
9. Enter an explicit zero in another score, leave a different score blank, sort complete Student rows, save, upload, and confirm stable-identity mapping plus correct zero/blank behavior.
10. Try a negative, over-maximum, text, formula, wrong extension, and file over 5 MB; verify upload stays on Step 3 or Review with a clear error and never advances to Apply.
11. Duplicate/remove a Student row or rename/remove/reorder an assessment column; verify Review displays blocking errors and disables Apply.
12. Use Back, completed progress steps, `Upload another file`, and `Start over`; verify state changes are understandable and no grade is written.
13. Select or preview a file, then use backdrop, Escape, and the close button; verify each appropriate close warns that local workflow state will be cleared and no grade will change. Verify focus returns to `Edit in Excel`.
14. Create an unsaved browser score and reopen the dialog; verify the edit remains visible after closing, the warning appears, and download/upload/apply stay blocked until the edit is intentionally saved or discarded.
15. Export, change the corresponding browser score or assessment in another session, then upload/apply the older workbook and verify conflict handling with no partial writes or false success message.
16. Close the grading period or submit the sheet and verify preview/apply reports the lock.
17. Confirm Apply is enabled only for a clean, active proposal. Cancel the browser confirmation once and verify no grades change.
18. Apply successfully and verify the exact saved count, refreshed cells, recalculated grades, refreshed readiness, cleared workflow state, and unchanged draft/returned submission status.
19. Double-click or retry the completed request and verify no duplicate score versions or audit operation is created.
20. Leave a proposal open past 10 minutes and verify Apply disables and a new preview is required.
21. Confirm direct browser score editing, Save changes, Add Assessment, Edit Columns, Bulk Entry, Criteria, and Grade Submission still work.
22. Check the dialog at desktop, tablet, and narrow mobile-browser widths in Light, Dark, and System modes; verify horizontal review scrolling, readable progress, and reachable footer actions.
23. Attempt the workflow from another Professor, Student, Android client, and Electron client; verify backend access is denied where applicable and the Website-only action is absent outside Website Professor Grade Entry.
