# CDM Portal UI 2.0 — Professor Grading 3.0

## Scope and safety

This phase changes only the website Professor grading presentation. It reuses the existing grading services, backend authorization, formulas, readiness validation, final-weight endpoint, and Registrar submission endpoint. No Laravel files, environment files, database schema, migrations, or records were changed.

Recovery checkpoint: `/tmp/cdm-grading-3.0.yj88jO`. It contains the original `dev` branch and HEAD metadata, stash inventory, tracked working-tree patch, untracked-file archive, and SHA-256 checksums.

## Grade Entry mapping to the supplied reference

- A compact `GRADE ENTRY` heading, return-to-classes action, subject/section identity, academic term, current period, roster count, and sheet status replace the previous large stacked summary on the website workspace.
- A contextual Grade Entry / Grade Submission navigation row keeps both Professor workflows directly connected.
- Midterm, Finals, and Final Grade remain the existing grading-period selectors.
- Search, Add Assessment, Edit Columns, Bulk Entry, and Criteria are grouped in one compact toolbar.
- The main desktop area uses a wide spreadsheet on the left and a compact grading-criteria table on the right.
- The score grid uses real active assessments, groups them by their configured categories, and shows assessment labels and maximum scores beneath the category headings.
- Student identity columns and table headers remain sticky. Wide grade sheets scroll inside their own region instead of widening the page.
- Inactive score inputs are borderless, one focused cell receives the selection ring, native number spinners remain hidden, and the calculated period grade comes from the existing readiness result.
- The criteria table uses current-period weight records and derives category maximum scores from real active assessments. The total is displayed and saving uses the existing period-weight endpoint.

The supplied screenshot's sidebar, mock students, example scores, and sample percentages were not copied. CDM Portal navigation, semantic colors, and real data remain authoritative.

## Grade Submission architecture

`/grading/submission` is a real lazy-loaded Vue route backed by `ProfessorGradeSubmissionView.vue`.

- Access is restricted to Professor in `ROUTE_ROLES` and to the `web` client in route metadata.
- The Grading navigation group remains active on the route and exposes a Grade Submission child destination.
- A class query parameter keeps the selected assigned class when moving between Grade Entry and Grade Submission.
- The page loads the existing workspace and readiness responses, displays grade-data completeness, the finals submission window, Midterm/Finals/other blockers, final-grade weights, and the authoritative submit state.
- Saving final weights still calls `gradingService.saveFinalWeights`.
- Submission still calls `gradingService.submit`, remains disabled until the existing readiness and window rules pass, and now requires an explicit confirmation.
- Links from blocker groups return to the relevant Grade Entry period.
- Unsaved Grade Entry scores are protected both by the workflow button and a route-leave confirmation.

The existing non-web embedded readiness interface remains in the legacy Android/Electron presentation; only the website Professor presentation moved to the separate route.

## Preserved grading behavior

- Automatic assessment label suggestions and custom labels.
- Add and bulk-add assessment APIs and validations.
- Edit Columns mode, renaming, archival confirmation, and protection against pending score edits.
- One `Save changes` action for pending score cells.
- Existing category and final-grade weight APIs.
- Existing formulas, published-grade restrictions, grading schedules, Registrar review, release workflow, and role authorization.
- Student grading, staff grading management, Android, and Electron markup paths.

The score-save loop now removes drafts one successful API batch at a time. If a later batch fails, confirmed batches are reported as saved and only the unsuccessful cells remain pending.

## Files added

- `CDM_Frontend/src/modules/grading/ProfessorGradeSubmissionView.vue`
- `CDM_Frontend/test/gradingThree.test.js`
- `docs/UI_2_GRADING_3_0.md`

## Files modified

- `CDM_Frontend/src/modules/grading/GradingView.vue`
- `CDM_Frontend/src/modules/grading/grading.css`
- `CDM_Frontend/src/modules/grading/gradeSpreadsheet.js`
- `CDM_Frontend/src/router/index.js`
- `CDM_Frontend/src/config/accessControl.js`
- `CDM_Frontend/src/config/websiteNavigation.js`
- `CDM_Frontend/test/gradingSpreadsheet.test.js`
- `CDM_Frontend/test/gradingAssessmentReadiness.test.js`
- `CDM_Frontend/test/gradingFocusReadiness.test.js`

## Responsive and theme behavior

- At desktop and 1024px-class widths, the spreadsheet receives the flexible column and criteria remains a compact 230–285px panel.
- Below 840px, criteria moves below the contained horizontally scrolling grade table.
- Toolbar controls and metadata wrap at 620px and collapse to one column at 390px where necessary.
- Styling uses existing semantic surface, border, text, accent, success, warning, and danger tokens for Light, Dark, and System themes.
- No transforms, navigation dimensions, global transitions, or connected rail animation rules were changed.

## Validation results

- Focused grading tests: passed (5 focused files, including Grading 3.0 route/layout checks and partial-batch reconciliation).
- Full frontend suite: passed, 61 test files and 0 failures.
- Website production build: passed with `VITE_API_BASE_URL=https://example.invalid/api` as an inert build-time validation URL; no environment file was changed.
- Android mobile-development build: passed.
- Electron renderer build (`vite build --mode desktop-development`): passed.
- `git diff --check`: passed.

No database command was run. Browser screenshot automation was not available in the current toolset, so reference comparison still requires manual visual UAT.

## Manual UAT checklist

1. Sign in as a Professor on the website and open Grading.
2. Open an assigned class and confirm only the compact Grade Entry header appears.
3. Compare the desktop arrangement with the supplied reference at 1440px and 1024px: toolbar at top, tabs above the work area, spreadsheet left, criteria right.
4. Switch Midterm and Finals. Confirm real columns, criteria rows, weights, schedule state, and calculated grades update.
5. Search by student name and student number.
6. Enter valid and invalid scores, use keyboard Enter navigation, then verify the single Save changes action and dirty/invalid feedback.
7. Simulate or observe a partial save failure and confirm successful cells clear while failed cells remain pending.
8. Add an assessment using its suggested label, replace it with a custom label, bulk-add valid rows, rename a column, and confirm archival before removal.
9. Change a criteria percentage, verify the displayed total, save criteria, and reload to confirm persistence.
10. With an unsaved score, click Grade Submission and navigate through another sidebar destination; verify the loss protection.
11. Open Grade Submission with a selected class. Review Midterm, Finals, and other blockers, final weights, window, and status.
12. Verify submission stays disabled when the server reports blockers or a closed/not-open window.
13. On a ready sheet, verify submission requires confirmation and sends only after confirmation.
14. Confirm Student, Guest, Registrar, Admin, Android, and Electron cannot open the website-only Professor submission route.
15. Repeat at 768px, 390px, and 320px; confirm table scrolling is contained and criteria stacks without page overflow.
16. Repeat in Light, Dark, and System themes and verify print-oriented documents remain unchanged.
