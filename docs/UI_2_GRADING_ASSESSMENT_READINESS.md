# CDM Portal UI 2.0 — Automatic Assessment Labels and Submission Readiness

## Scope and recovery

This change refines only the Website Professor Grading interface. The existing spreadsheet score grid, single score-save action, grading formulas, required categories, weight rules, deadlines, authorization, submission endpoint, Registrar review, and publication flow remain unchanged.

The pre-change recovery checkpoint is `/tmp/cdm-grading-readiness.DfMxnQ`. It records branch `dev` at `bf7a6893b525662e1edb56e9ac1e1f9d1e1ad828` and contains the tracked binary patch, untracked-file archive, index patch, Git status, stash list, and SHA-256 manifest. Existing stashes and earlier recovery checkpoints were not modified.

## Automatic assessment labels

When a Website Professor opens Add Assessment, the dialog uses the existing default category and immediately suggests the next numbered label. Suggestions use only assessments already loaded for the current class, term, and active grading period; no request or backend endpoint was added.

The supported categories remain the existing `Quiz`, `Activity`, `Recitation`, and `Major Exam` values supplied by the grading workspace. Matching trims surrounding whitespace and is case-insensitive. The next number is one greater than the highest exact `<category> <number>` suffix. Existing labels are also checked globally to avoid generating an exact duplicate.

The label remains an editable text input:

- An untouched automatic label changes when the category changes.
- Once the Professor types in the field, that custom label is preserved across category changes.
- `Use next available` restores the current category suggestion.
- Editing an existing assessment never automatically renames it.
- Closing and reopening Add Assessment recalculates from the current loaded period data.
- After creation, the existing workspace reload makes the new assessment part of the next calculation.

The existing Add/Edit Assessment API, label length and required rules, category validation, maximum score, display order, permissions, and backend duplicate validation remain authoritative. The outgoing label is trimmed as before.

## Submission Readiness redesign

The former dense Website warning block is replaced by one structured submission panel:

- a heading and concise explanatory text;
- a visible Ready, Incomplete, Closed, or Not Open badge derived from existing readiness and submission-window data;
- separate Grade data and Submission window indicators;
- an accurate total number of blocking issues;
- native keyboard-operable disclosure sections for Midterm, Finals, and Other requirements;
- period shortcuts that switch the existing grading-period tab and move to its assessment workspace;
- a separate Final grade weights subsection with aligned inputs, total percentage, and the existing Save final weights action;
- a footer containing the existing Submit/Resubmit action and a concise reason when it is disabled.

Every backend readiness message remains present. Grouping is deliberately conservative:

1. Messages beginning with `Midterm` or `Finals` go to that period.
2. Student score messages containing a loaded assessment label inherit that assessment's period.
3. Messages that cannot be classified safely go to Other requirements.

No fixed requirements are hardcoded and no message is rewritten or discarded. `readiness.ready`, the existing Finals submission-window state, eligible sheet statuses, and the existing `canSubmit` expression continue to control submission.

## Presentation and accessibility

The new panel uses existing semantic Light/Dark/System tokens. Statuses always include text, not color alone. Disclosure summaries are native `<summary>` controls with visible keyboard focus. Inputs retain explicit labels, the disabled submit action references its explanation, mobile layouts stack at narrow widths, and reduced-motion preferences suppress optional transition/scroll behavior.

The new readiness markup is behind `isWebsitePresentation`. The legacy readiness and assessment-dialog presentation remains in the non-web branch. Automatic label initialization is also gated by the compile-time website client check. Android and Electron therefore retain their prior presentation and empty Add Assessment label behavior.

## Files changed for this step

- `CDM_Frontend/src/modules/grading/GradingView.vue`
- `CDM_Frontend/src/modules/grading/grading.css`
- `CDM_Frontend/src/modules/grading/assessmentPresentation.js` (new)
- `CDM_Frontend/test/gradingAssessmentReadiness.test.js` (new)
- `docs/UI_2_GRADING_ASSESSMENT_READINESS.md` (new)

The existing dirty worktree includes earlier UI 2.0, CDM Buddy, academic, deployment-readiness, and grading-spreadsheet changes. Those files were preserved and are not claimed as part of this focused step.

## Automated validation

- Focused assessment-label, readiness, spreadsheet, and grading workflow tests: **passed**.
- Full frontend test suite: **passed — 58/58 test files**.
- Website production build: **passed** with command-only inert `VITE_API_BASE_URL=https://build.invalid/api`; no `.env` file changed.
- Android mobile-development build: **passed**.
- Electron desktop-development renderer build: **passed** with command-only inert `VITE_DESKTOP_API_BASE_URL=https://build.invalid/api`; no packaging was performed.
- `git diff --check`: **passed**.

No command in this step accesses Laravel, MariaDB, migrations, seeders, or a deployed portal service.

## Manual visual UAT

1. Sign in as a Website Professor and open an assigned class in Grading.
2. At 1440px and 1024px, confirm the readiness header, two status indicators, requirement disclosures, weight controls, and submission footer have clear separation without excess empty space.
3. Repeat at 768px, 390px, and 320px. Confirm sections stack, buttons fit, requirement text wraps, and the page has no horizontal overflow outside the intended score grid.
4. Check Light, Dark, and System themes for readable status colors, borders, focus rings, inputs, disclosure surfaces, and disabled actions.
5. With incomplete data, compare every displayed issue against the existing readiness response. Expand all Midterm, Finals, and Other groups and confirm no message is missing.
6. Activate each disclosure using Enter and Space. Use the period shortcuts and confirm the existing Midterm or Finals assessment workspace opens.
7. Test Ready, Incomplete, Open, Closed, and Not Open combinations available in local fixtures. Confirm submission eligibility is unchanged.
8. Change final weights, save them independently, and confirm the total indicator updates without saving scores or bypassing server validation.
9. Open Add Assessment with no existing category item and confirm `<Category> 1`. Create it, reopen the dialog, and confirm the next number.
10. Test a numerical gap, case differences, and surrounding whitespace in existing labels. Confirm the suggestion follows the highest suffix and is not an exact duplicate.
11. Type a custom multiword label, switch categories, and confirm it remains unchanged. Select `Use next available` and confirm automatic category naming resumes.
12. Edit an existing assessment and confirm its label is not replaced automatically.
13. Inspect Android and Electron Professor Grading to confirm their prior readiness layout and Add Assessment initialization remain unchanged.
14. Enable reduced motion and verify the period shortcut does not use smooth scrolling.

## Remaining manual limitations

No browser-rendering or screenshot tool was available for this pass. Successful source tests and builds do not establish pixel-perfect alignment, real-device touch behavior, browser zoom behavior, or visual acceptance. These items remain for the manual UAT above.
