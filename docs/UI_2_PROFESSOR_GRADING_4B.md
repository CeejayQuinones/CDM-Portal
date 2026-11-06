# CDM Portal UI 2.0 — Professor Grading Phase 4B

## Scope

Phase 4B adds safe local draft recovery, fast Student/assessment navigation, and measured model-level large-class optimizations to the Website Professor Grade Entry workspace. It preserves the Phase 4A selection, clipboard, and undo/redo model; the Phase 3 Edit in Excel workflow; the separate Grade Submission page; and all existing grading APIs and academic rules.

The feature is rendered only by the Website Grade Entry branch (`VITE_CDM_CLIENT=web`). Android and Electron keep their existing presentation. No Laravel contract, database schema, migration, seeder, or environment file was changed for this phase.

## Recovery storage design

Temporary recovery is disabled by default and must be enabled by the Professor. The control explains that score drafts will be kept temporarily in the current browser tab and warns about shared devices.

Recovery uses `sessionStorage`, not persistent `localStorage` or a server cache. Writes are debounced by 650 ms so score entry does not synchronously serialize storage on every keystroke. Each record is scoped to:

- authenticated account ID;
- assigned class ID;
- stable subject and section IDs;
- grade-sheet ID and data version;
- academic-year and semester IDs and labels;
- grading period;
- assessment IDs and versions; and
- base score versions when a score already exists.

The storage key contains only scoped identifiers. A recovery entry contains enrollment-subject IDs, assessment IDs/versions, base-presence and base-version metadata, draft values, and timestamps. It deliberately excludes Student names, Student numbers, access tokens, and class display names.

## Privacy and expiration controls

- Recovery is opt-in per authenticated account within the browser tab session.
- Records expire after 12 hours and are removed when found expired or malformed.
- Turning recovery off removes that account's recovery copies and preference from the current tab after confirmation when pending work exists.
- Successful save and explicit recovery discard remove the corresponding copy.
- Logout/authentication clearing removes every recovery key for the departing account while leaving other account namespaces untouched.
- Browser storage is not treated as an authorization boundary. Recovery is offered only after the current authorized workspace and current server scores load.
- Session storage may be unavailable, evicted, blocked in private browsing, or lost when a tab/session is closed. The UI does not promise recovery across devices or every browser failure.

## Conflict handling

Recovery never writes to Laravel or silently changes server grades. After current authoritative data loads, the client checks:

1. the full recovery scope and grade-sheet data version;
2. the Professor's current authorized roster;
3. whether the grading period is still editable;
4. assessment presence, active status, period, and version; and
5. each score's original presence and base version.

A found copy is shown as **Unsaved grading work found** with its class, period, edit count, approximate time, and eligible/conflicting counts. The Professor must choose Restore Draft or Discard Recovery. Conflicting cells are isolated and never restored. If eligible and conflicting changes are mixed, restoring eligible items requires an additional deliberate confirmation that the conflicts will be discarded.

Restored scores remain ordinary unsaved browser drafts. The restore is one Phase 4A undoable operation; it does not call a save endpoint.

## Recovery lifecycle and editing safety

- Direct edits, valid pasted ranges, undo, and redo schedule a recovery update when recovery is enabled.
- Recovery remains unresolved until explicitly restored or discarded; score inputs and risky grading actions are disabled during that decision.
- Subject exit and route navigation warn about pending edits. With recovery enabled, navigation proceeds only after the recovery copy is successfully stored and the Professor confirms. Without recovery, the warning explicitly says the changes will be discarded.
- Period switching is blocked while browser edits or an unresolved recovery exist, preventing period mixing.
- Edit in Excel and Grade Submission remain blocked until recovery is resolved, and both continue to protect ordinary unsaved score edits.
- A full save clears recovery and undo/redo history at the new authoritative-data boundary.
- During a multi-assessment save, each successful batch is removed from drafts and the remaining recovery copy is rewritten. If a later batch fails, only unsaved cells remain recoverable and the UI reports partial success accurately.
- Discard clears browser drafts, history, selection state, and the corresponding recovery copy.

## Find Student and jump to assessment

The visible **Find Student** control searches by name or Student number, provides keyboard-accessible results, and includes an assessment selector and **Jump to score** action.

- Student and assessment resolution uses stable enrollment-subject and assessment IDs.
- The jump resolves against the current filtered roster order, focuses the exact score input, scrolls it into view, briefly applies a semantic highlight, and announces the destination through the existing live status region.
- `Ctrl+F`/`Cmd+F` focuses this control only while the gradesheet region or a score cell has focus. Browser Find and the portal's global search remain unchanged elsewhere.
- The search query/results live in a child component. The parent roster filter receives a 180 ms debounced update, avoiding a full Grade Entry component update on every key event.
- Reduced-motion users receive an immediate scroll and no highlight transition.

## Performance work

- Score selection continues to use targeted DOM class updates rather than reactive state updates for every selected cell.
- Stable IDs are used for selection, search, filtering, and jump resolution.
- Search result calculation is isolated from the large parent gradesheet and table filtering is debounced.
- Recovery serialization is debounced, contains only dirty cells, and makes no network request.
- Existing bounded undo/redo operations and range/paste validation remain intact.
- Row virtualization and a grid library were intentionally not introduced. Browser profiling evidence was unavailable, and virtualization would risk table semantics, selection, and focus behavior.

## Benchmark methodology and results

`node scripts/benchmark-grading-clipboard.mjs` ran each operation 40 times with generated, non-production fixtures. Measurements use Node `performance.now()` and cover pure model operations only. They do **not** measure DOM rendering, scrolling FPS, paint time, Vue patching, or network behavior.

### Generated matrix — median milliseconds (cell projection / full range map)

| Students | 10 assessments | 20 assessments | 40 assessments |
| --- | ---: | ---: | ---: |
| 30 | 0.066 / 0.069 | 0.075 / 0.063 | 0.106 / 0.230 |
| 100 | 0.102 / 0.139 | 0.221 / 0.207 | 0.333 / 0.322 |
| 300 | 0.177 / 0.277 | 0.663 / 0.713 | 1.056 / 1.521 |

### Interaction model — median / observed maximum milliseconds

| Operation | Median | Maximum |
| --- | ---: | ---: |
| Single-cell local edit | 0.001 | 0.158 |
| Keyboard coordinate lookup | 0.011 | 0.155 |
| One undo and redo | 0.002 | 0.379 |
| 100-cell paste validation and apply | 0.181 | 1.937 |
| Student search and assessment jump resolution | 0.105 | 0.867 |
| 100-edit recovery create, serialize, and validate | 1.228 | 4.987 |
| 20-column grading model build | 0.018 | 0.233 |

Playwright, Puppeteer, Chromium, Chromium Browser, and Google Chrome were not available in the local toolchain. Browser initial-render, vertical/horizontal scrolling, and paint measurements therefore remain manual UAT items. No FPS or browser-latency claim is made from these Node results.

## Files changed for Phase 4B

### Added

- `CDM_Frontend/src/modules/grading/GradeFindControl.vue`
- `CDM_Frontend/src/modules/grading/gradeDraftRecovery.js`
- `CDM_Frontend/src/modules/grading/gradeFindNavigation.js`
- `CDM_Frontend/test/gradingDraftRecovery.test.js`
- `CDM_Frontend/test/gradingFindNavigation.test.js`
- `docs/UI_2_PROFESSOR_GRADING_4B.md`

### Modified

- `CDM_Frontend/src/modules/grading/GradingView.vue`
- `CDM_Frontend/src/modules/grading/grading.css`
- `CDM_Frontend/src/stores/authStore.js`
- `CDM_Frontend/scripts/benchmark-grading-clipboard.mjs`
- `CDM_Frontend/test/gradingColumnRefinement.test.js`

## Automated validation

Run on 2026-10-28:

- Full frontend suite: **69/69 test files passed**.
- Focused Phase 4A/4B, column, and Excel compatibility suite: **5/5 test files passed**.
- Website production build with inert build-time API URL (`https://example.invalid/api`): **passed**, 356 modules, Vite build 14.52 s, PWA generated.
- Android mobile-development renderer build: **passed**, 350 modules, Vite build 15.62 s, PWA generated.
- Electron desktop-development renderer build: **passed**, 350 modules, Vite build 12.16 s, PWA generated.
- `git diff --check`: **passed**.
- Laravel tests: not run because Phase 4B did not change backend contracts and the task prohibited development-database access.

## Manual browser UAT checklist

Use a non-production Professor test account and disposable grading fixtures. Verify Light, Dark, and System appearance at desktop and narrow website widths.

1. Open Grade Entry and confirm temporary recovery is off by default for a fresh browser session/account.
2. Enable recovery and read the shared-device/temporary-session disclosure.
3. Edit scores directly, paste a multi-cell Excel range, then undo and redo. Confirm the unsaved count and Save changes behavior remain correct.
4. Refresh the page in the same tab. Confirm authoritative data loads first, then the recovery card appears without changing any displayed server value automatically.
5. Restore an eligible recovery. Confirm it becomes unsaved browser work, is undoable as one operation, and is not submitted or saved until Save changes is pressed.
6. Repeat and choose Discard Recovery. Confirm authoritative scores remain unchanged.
7. Create a recovery, then alter a score or assessment version through another authorized session. Refresh and confirm the changed item is listed as a conflict and is never restored.
8. Test a removed/archived assessment, changed sheet version, removed roster member, and locked period. Confirm each recovery item is blocked with a clear reason.
9. Verify Midterm and Finals copies never mix. Attempt to switch periods or classes with pending edits and review both opt-in and opt-out warnings.
10. Save several assessment batches while simulating a later request failure. Confirm saved batches remain saved, remaining drafts remain visible/recoverable, no false all-saved message appears, and undo history resets at the saved-data boundary.
11. Open Edit in Excel and Grade Submission with pending edits and with unresolved recovery. Confirm both are protected; after resolving/saving, confirm their existing workflows still operate.
12. Search by full/partial name and Student number. Use Arrow Down, Enter, Escape, clear, assessment selection, and Jump to score. Confirm focus and highlight target the correct stable Student after filtering.
13. Press `Ctrl+F`/`Cmd+F` inside the gradesheet and outside it. Confirm only the in-gradesheet shortcut opens Find Student and browser/global search behavior remains available elsewhere.
14. Exercise 30×10, 100×20, and 300×40 generated/manual fixtures. Inspect initial render, typing, vertical/horizontal scroll, 100-cell paste, undo/redo, search/jump, and recovery using browser Performance tools.
15. Verify the repaired non-sticky table geometry, Light/Dark/System colors, reduced-motion behavior, keyboard focus, and mobile horizontal overflow.
16. Log out with an enabled recovery, then sign in as another Professor in the same tab. Confirm the prior account's recovery is not offered.
17. Confirm Android and Electron retain their existing Grade Entry presentation and do not show the Website recovery/find controls.

## Recovery checkpoint

The verified pre-change checkpoint is stored at:

`/home/kenneth/projects/CDM_Portal/.local-recovery/professor-grading-4b-prechange-20261028`

- Tracked patch SHA-256: `e106a7076372f5373f51eef3ec2f3c81159ea87bf2f5ad35058f877848f2332a`
- Untracked archive SHA-256: `01fb948dece55348410b907ba932dcdda29b80e2028935a602cec345f0e40818`

The checkpoint and all three pre-existing Git stashes remain untouched.
