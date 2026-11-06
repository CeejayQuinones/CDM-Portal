# CDM Portal — Professor Grading 4B.1 and 5.1 Find Student Navigation

## Scope

Phase 4B.1 repaired the Website Professor Grade Entry Find Student workflow. Phase 5.1 removes the redundant Jump button and navigates immediately after deliberate Student selection or a selected-assessment change. Neither phase changes grade calculations, score saving, clipboard editing, undo/redo, draft recovery, Edit in Excel, Grade Submission, permissions, backend contracts, or Android/Electron presentation.

## Confirmed cause

The failure had both layout and interaction causes:

1. The Grade Entry toolbar allocated a maximum 420px first column to a Find component whose internal grid required a search field, assessment field, Jump button, and shortcut badge with combined minimum widths greater than that column. Under the later toolbar cascade, the input could collapse or clip while the icon and floating results remained visible.
2. Search suggestions were coupled to the parent roster filter. Each delayed query updated the full Grade Entry roster rather than keeping suggestion work local.
3. Choosing a suggestion immediately emitted a jump, while the visible Jump button was enabled whenever any search result existed. There was no durable selected-Student state, so the button's activation requirements were unclear and the first match effectively behaved like an implicit selection.
4. The absolute result panel inherited the constrained search width and competed with clear controls in the collapsed geometry.

The repair changes the state model first, then supplies dimensions that support that model.

## Search UX changes

- **Find Student** is a permanent, full-width input with placeholder `Search student name or ID...`.
- The search icon is decorative inside the field and is never the only entry point.
- Search uses only the already-authorized roster supplied by the current grade workspace. It makes no API request while typing.
- The roster is normalized into a computed search index only when the roster changes. Result lookup is bounded to eight matches.
- Suggestions appear directly beneath the input and show Student name and Student number.
- No result is selected merely because it is first. The active result index begins at `-1`.
- Choosing a result stores its stable `enrollment_subject_id`, closes suggestions, and displays a clear **Selected** state. The Student remains selected until the Professor changes the query, uses Clear, chooses Change, or the authorized roster removes that ID.
- No-results feedback reads `No Students match this search.`
- Pointer interaction outside the component dismisses suggestions without clearing a valid selection.

The component no longer emits parent filter events, so typing does not filter or rerender the full spreadsheet.

## Automatic navigation behavior

Phase 5.1 removes the Jump to score button, its disabled-state helper, and its third toolbar column. Navigation occurs only when:

1. a Professor deliberately chooses an authorized Student suggestion and an active assessment exists; or
2. the Professor changes the assessment while a Student remains selected.

Typing never navigates. It clears the prior selection and keeps suggestion lookup local to the component. The selected Student's name and number remain visible after navigation, and changing assessments preserves that stable `enrollment_subject_id`.

The parent resolves the target through stable enrollment-subject and assessment IDs against the currently rendered roster and normalized assessment columns. It then:

1. derives the exact score-cell key;
2. applies the existing Phase 4A selection model;
3. focuses the actual editable score input;
4. scrolls that cell into the spreadsheet viewport immediately using nearest-edge alignment;
5. applies the existing temporary semantic highlight; and
6. announces the destination through the score-selection live region.

Navigation never changes a score, writes to the backend, or touches clipboard, undo/redo, or recovery history. If the period is read-only, the Student selection is preserved but no disabled input is focused. If the Student or assessment is unavailable or not rendered, Grade Entry reports and announces a concise error and does not fall back to another row.

## Keyboard accessibility

The input follows the combobox/listbox pattern:

- `Arrow Down` and `Arrow Up` move the active suggestion.
- `Enter` selects only the explicitly active suggestion.
- `Escape` closes suggestions.
- `aria-activedescendant` tracks the active option.
- Results use listbox/option semantics and expose `aria-selected` only for keyboard navigation.
- `Ctrl+F`/`Cmd+F` continues to focus this input only when focus is within the gradesheet region.
- The normal browser Find and portal topbar search remain unchanged elsewhere.

## Responsive and stacking behavior

- The desktop toolbar now reserves a viable `minmax(540px, 1.3fr)` Find area and a separate assessment-management action area.
- At 1180px and below, those two groups stack before their minimum widths can collide.
- Inside Find, desktop uses two columns: a wider visible search field and the assessment selector.
- At 620px, search takes a full row and the assessment selector uses the next row.
- At 390px, all controls form one column.
- The input explicitly uses `display: block`, `box-sizing: border-box`, `min-width: 0`, and `width: 100%` to prevent intrinsic-width collapse.
- The suggestion panel is anchored to the search field, and the focused search establishes a local stacking level above adjacent toolbar controls.
- No page-level horizontal overflow, sticky columns, or navigation/topbar changes were introduced.

## Performance considerations

- Search indexing is computed from the authorized roster and reused for every query.
- Results are bounded to eight.
- Search state stays inside `GradeFindControl.vue`; it no longer updates the full gradesheet roster per keystroke.
- Stable IDs remain the only identity used for selection and target resolution.
- Phase 4A non-reactive selection painting remains unchanged.

The generated 300-Student test performs 1,000 indexed searches under a deliberately generous 500ms regression ceiling. The updated Node model benchmark measured indexed Student search plus assessment jump resolution at a 0.040ms median and 0.578ms observed maximum over 40 runs. These are model measurements, not DOM-render or browser-FPS claims.

No Playwright, Puppeteer, or Chromium binary is installed, so visual clipping, real browser paint, and scroll smoothness remain manual UAT requirements.

## Files changed for 4B.1

- `CDM_Frontend/src/modules/grading/GradeFindControl.vue`
- `CDM_Frontend/src/modules/grading/gradeFindNavigation.js`
- `CDM_Frontend/src/modules/grading/GradingView.vue`
- `CDM_Frontend/src/modules/grading/grading.css`
- `CDM_Frontend/scripts/benchmark-grading-clipboard.mjs`
- `CDM_Frontend/test/gradingFindNavigation.test.js`
- `CDM_Frontend/test/gradeEntryRefinement.test.js`
- `docs/UI_2_GRADING_FIND_STUDENT_FIX.md`

## Phase 5.1 validation results

Run locally on 2026-10-28:

- Focused Find/navigation, toolbar, clipboard, draft recovery, and Phase 5 stabilization tests: **5/5 test files passed**.
- Full frontend suite: **70/70 test files passed**, 0 failed, in 14.586 seconds.
- Website production build using inert build-time API URL `https://example.invalid/api`: **passed**, 356 modules, Vite build 12.60s, PWA generated.
- Android mobile-development renderer build: **passed**, 350 modules, Vite build 12.20s, PWA generated.
- Electron desktop-development renderer build: **passed**, 350 modules, Vite build 11.28s, PWA generated.
- `git diff --check`: **passed**.
- Laravel tests were not run because no backend contract changed.
- No database, migration, seeder, `.env`, deployment, or production service operation was performed.

The frontend suite still prints the existing non-failing Node/esbuild warning that `import.meta` is unavailable when a specific test bundles `clientPlatform.js` as CommonJS. It does not affect the ESM Vite builds.

## Recovery checkpoint

The durable pre-change worktree archive is:

`/home/kenneth/projects/CDM_Portal/.local-recovery/professor-grading-4b1-prechange-20261028/worktree.tar.gz`

SHA-256: `f63f2a94d437ef7c3d43fb6fb84d753ad235d3410b466f9dec50da79582d7cb3`

The previous Phase 4B checkpoint and all three Git stashes remain untouched.

## Manual browser UAT

1. Open Website Professor Grade Entry at desktop widths above 1180px. Confirm the wider Find input, assessment selector, and management actions fit without overlap and no Jump button is present.
2. Repeat at 1180px, 1024px, 768px, 390px, and 320px. Confirm predictable wrapping and no page-level horizontal overflow.
3. Click anywhere in the search input and type a partial first name, last name, and Student number. Confirm readable bounded suggestions appear directly below it.
4. Confirm the first match is not selected automatically and typing alone never moves spreadsheet focus. Use Arrow Down/Up, Enter, and Escape; verify active-option announcements with a screen reader.
5. Select a Student by pointer. Confirm navigation happens immediately and the selected name/number remains visible.
6. Change assessments several times. Confirm the same selected Student is retained and the exact chosen assessment input immediately receives focus and highlight.
7. Use Clear and Change. Confirm selection is intentionally removed and later assessment changes do not navigate until another Student is selected.
8. Test a no-results query and click outside the suggestions. Confirm the panel closes cleanly without floating or overlapping controls.
9. With a changed roster or removed assessment fixture, confirm automatic navigation reports an unavailable target and never focuses another Student.
10. Press `Ctrl+F`/`Cmd+F` inside and outside the gradesheet. Confirm Grade Entry Find receives focus only inside its scope.
11. Exercise a roughly 300-Student, 40-assessment fixture and inspect typing, result opening, jump, horizontal scroll, and focus using browser Performance tools.
12. Recheck copy/paste, undo/redo, draft recovery, Save changes, period switching, Edit in Excel, and Grade Submission.
13. Verify Light, Dark, and System themes.
14. Confirm Android and Electron retain their prior Grade Entry presentation and do not show the Website-only control.
