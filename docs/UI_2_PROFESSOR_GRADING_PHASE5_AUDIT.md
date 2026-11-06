# CDM Portal UI 2.0 — Professor Grading Phase 5 Audit

## Scope and safety

This phase audited and stabilized the Website Professor Grade Entry workflow on local branch `dev`. It did not change grading formulas, academic policy, Laravel endpoints, database schema, environment files, Android/Electron presentation, or production services. No migration, seeder, MariaDB operation, commit, push, merge, or deployment was performed.

The pre-change recovery checkpoint is `.local-recovery/professor-grading-phase5-prechange-20261028/`:

- `tracked.patch` — SHA-256 `a3e682206908891748332f1e89a369567a65def0181cc17060052c47333b713c`
- `untracked.tar.gz` — SHA-256 `ab0ae3019ee8446e8cb9b5fcd7d7bc1d268e224d39f5411f3714f57d157d1d34`

The three existing Git stashes were inspected and preserved.

## Audited workflow inventory

The current Professor grading workspace includes:

- class and grading-period selection;
- assessment creation, automatic labels, label editing, bulk creation, and archival;
- direct score entry with validation, save/discard controls, clipboard copy/paste, keyboard navigation, selection, undo, and redo;
- local draft recovery with account/class/period scope and server-version conflict review;
- explicit Find Student selection and Jump to score;
- Edit in Excel download, upload preview, conflict review, and transactional draft apply;
- criteria/weight editing and calculated readiness feedback;
- a separate Grade Submission route and Registrar review/publication status.

Phase 4B.1 remains intact: the Find Student input is permanently visible, results appear below it, selection retains the stable `enrollment_subject_id`, Jump requires both a selected student and a valid active assessment, and a jump scrolls to, focuses, selects, and briefly highlights the intended score cell. Keyboard selection remains explicit; typing does not silently select the first result.

## Confirmed defects and repairs

### Atomic class refresh

Previously, `openClass` replaced the live workspace before assessment-readiness loading completed and swallowed refresh errors. A readiness failure could therefore combine a newly loaded class with stale browser score drafts. It also prevented save/import callers from distinguishing a successful server write from a failed UI refresh.

The workspace and readiness payloads are now staged locally and installed together only after both requests succeed. On failure, the existing live workspace remains in place and `openClass` returns `false`.

### Accurate save and Excel apply feedback

Direct score save and transactional Excel apply now inspect the refresh result. They do not falsely report a fully refreshed success when the server write succeeds but the follow-up workspace refresh fails. The Professor receives an explicit message that the scores were saved but Grade Entry could not refresh, and can reload safely.

### Protection for pending browser score edits

Add Assessment, Bulk Entry, and Criteria save can refresh the authoritative workspace. These actions are now blocked while unsaved score drafts exist, with a concrete instruction to save or discard them first. Relevant Website controls are disabled during that state. Edit in Excel's existing pending-edit protection remains unchanged.

### Protection for pending column-label edits

Return to My Classes, Grade Submission, Vue route navigation, and browser unload now recognize active column-label editing. Navigation is blocked with an accurate message, and browser unload prompts rather than silently losing label changes. Score recovery continues to persist score drafts only; it does not misrepresent unsaved labels as recoverable scores.

## Data-integrity review

- Score cells continue to validate blanks versus explicit zero and assessment maximums before save.
- Direct score saves use the existing backend batch contract; this phase did not alter its server behavior.
- Undo/redo remains browser-local and scoped to score draft operations. It does not reverse server writes.
- Draft recovery remains account-, class-, and period-scoped and compares the stored server-version fingerprint before restoration.
- Excel Import Preview remains read-only and produces a cache-backed, expiring proposal.
- Excel Apply remains server-authoritative and transactional, revalidates the proposal, locks relevant records, detects conflicts, writes audit events, consumes the proposal once, and saves assessment scores as drafts only.
- Applying or directly saving scores never submits a grade sheet to the Registrar. Grade Submission remains an explicit separate workflow.
- Refresh failure handling now preserves truthful state: the UI never claims that unsaved scores were saved, and it distinguishes a confirmed server save from a failed client refresh.

No backend code or contract was changed in Phase 5.

## Performance audit

The repository benchmark `CDM_Frontend/scripts/benchmark-grading-clipboard.mjs` was run for 40 iterations per case. Results are JavaScript model-operation measurements in Node; they are not browser paint, DOM, memory, or FPS measurements.

| Operation | Median | Maximum |
| --- | ---: | ---: |
| 30 students × 10 assessments projection | 0.087 ms | 1.132 ms |
| 30 × 20 projection | 0.058 ms | 0.883 ms |
| 30 × 40 projection | 0.099 ms | 0.834 ms |
| 100 × 10 projection | 0.102 ms | 1.637 ms |
| 100 × 20 projection | 0.191 ms | 0.988 ms |
| 100 × 40 projection | 0.210 ms | 1.362 ms |
| 300 × 10 projection | 0.175 ms | 1.224 ms |
| 300 × 20 projection | 0.510 ms | 1.677 ms |
| 300 × 40 projection | 0.761 ms | 2.024 ms |
| Single edit | 0.001 ms | 0.194 ms |
| Keyboard-coordinate resolution | 0.019 ms | 0.257 ms |
| Undo and redo | 0.004 ms | 0.220 ms |
| 100-cell paste plan | 0.138 ms | 2.160 ms |
| Search and jump resolution | 0.042 ms | 0.495 ms |
| 100-edit recovery serialization | 1.061 ms | 4.073 ms |
| 20-column table model | 0.017 ms | 0.127 ms |

Range-selection measurements also remained below 1 ms median through 300 × 40; the largest observed range maximum was 4.413 ms at 300 × 20. These results show no model-layer bottleneck in the measured cases. Browser tooling was unavailable in this environment, so real DOM rendering, scroll smoothness, focus behavior, memory growth, and mobile viewport behavior remain manual UAT requirements.

## Files changed by Phase 5

- `CDM_Frontend/src/modules/grading/GradingView.vue` — atomic refresh, truthful refresh-failure handling, and unsaved score/column-edit guards.
- `CDM_Frontend/test/gradingWorkflowStabilization.test.js` — focused regression coverage for those safeguards.
- `docs/UI_2_PROFESSOR_GRADING_PHASE5_AUDIT.md` — this audit and validation record.

The worktree also contains preserved uncommitted files from earlier approved CDM Buddy, UI 2.0, grading, academic, and backend phases. They were not reset, stashed, or discarded.

## Automated validation

### Frontend

- Focused grading regression set: **7/7 files passed**.
- Full frontend suite: **70/70 files passed**, 0 failed, in 10.822 seconds.
- A non-fatal existing test-bundling warning notes that `import.meta` is empty in CommonJS output for `clientPlatform.js`.

### Backend

Using the repository PHPUnit configuration with disposable SQLite (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`), the relevant grading suites passed: **50 tests, 675 assertions**, in 48.837 seconds. This included grading Phases 1–3, grading scale, and Professor workbook/import behavior. No development MariaDB connection or write was used.

The complete Laravel suite and Pint were not rerun because Phase 5 touched no PHP file or backend contract; the focused backend coverage is the relevant safety check.

### Builds and static checks

- Website production build: passed; Vite transformed 356 modules in 11.88 seconds. `https://example.invalid/api` was supplied only as an inert build-time HTTPS URL.
- Android mobile-development build: passed; 350 modules in 11.11 seconds.
- Electron renderer development build: passed; 350 modules in 11.05 seconds.
- `git diff --check`: passed.

The platform builds confirm compilation isolation. They do not replace visual inspection; the Phase 5 behavior is gated by the existing Website presentation.

## Manual UAT checklist

Use a non-production local test account and disposable test data. Do not perform this checklist against the hosted presentation environment.

1. Open Professor Grade Entry with a small, medium, and large roster. Confirm the initial class and readiness state arrive together with no flash of another class's scores.
2. Enter valid, zero, blank, over-maximum, and nonnumeric scores. Confirm validation, dirty counts, save/discard state, and keyboard movement.
3. Copy/paste rectangular and sparse ranges; verify invalid cells are rejected and valid drafts can be undone/redone.
4. With unsaved scores, verify Add Assessment, Bulk Entry, Criteria save, Grade Submission, class return, and route changes cannot silently discard drafts.
5. Enter column-edit mode and change a label. Verify class return, Grade Submission, route changes, and tab/window closing warn or block until changes are saved/cancelled.
6. Save scores while simulating a failed refresh (for example, take the local API offline only after the save response). Confirm the message says the server save succeeded but refresh failed and never says unsaved data was saved when the save itself failed.
7. Enable draft recovery, create edits, reload, and review restore/discard behavior. Then change the same scores on the server and confirm version-conflict review prevents blind restoration.
8. Search by student number and name. Confirm typing alone does not enable Jump; explicitly choose a result, select an assessment, and confirm the exact cell scrolls into view and receives focus/highlight.
9. Complete Edit in Excel download, desktop Excel edit, preview, and apply. Check blank versus zero, expired proposal, repeated apply prevention, conflict handling, and refresh after success.
10. Confirm imported scores remain drafts and Grade Submission is still required before Registrar review.
11. Exercise Light, Dark, and System themes at 1440, 1024, 768, 390, and 320 px. Check wide-table scrolling, search suggestions, dialogs, focus rings, error announcements, and critical actions not covered by CDM Buddy.
12. Open Android and Electron builds and confirm their established presentation has not adopted the Website-only grading workspace changes.

## Remaining limitations

- Browser profiling and screenshots could not be produced because no supported browser automation/runtime was available.
- Large-roster DOM responsiveness, scroll behavior, focus retention, and screen-reader announcements require manual browser UAT.
- Network interruption after a confirmed write now has accurate messaging, but the precise persisted result must still be confirmed after reconnection/reload.
- No commit or deployment was made; the complete work remains local and uncommitted for review.
