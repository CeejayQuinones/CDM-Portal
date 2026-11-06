# CDM Portal Enrollment UI 2.0 — Reference Implementation

## Scope and source

This Website-only Student Enrollment refinement was adapted from the collaborators' `ENROLLMENT` repository at revision `b67543eb30c69548694659689f4e51a1b9d3dcb0` (`main`), inspected read-only on 2026-10-28. The detailed source audit and parity matrix are in `docs/ENROLLMENT_REFERENCE_PARITY.md`.

The implementation reproduces the reference application's clear application hierarchy, progress treatment, enrollment-type cards, review grouping, and document-tile presentation where CDM Portal already has authoritative data and APIs. It does not claim pixel-identical reproduction because the reference application was audited from source and no matched browser screenshots were available.

## Pages and workflow adapted

The reference `/enroll` experience maps to CDM Portal's existing Student `/enrollment/status` workflow. The Portal retains its four safe stages:

1. Personal and academic identity review using server-authoritative profile, Admission, Student, course, curriculum, year, and term data.
2. Enrollment classification selection for Regular, Irregular, Transferee, or Returnee.
3. Read-only Academic Path confirmation using the accepted or assigned program and curriculum.
4. Application review and configured document requirements before submission.

Existing Enrollment Subjects, Schedule, status history, versioned application lifecycle, confirmation dialogs, and Certificate of Registration remain unchanged. The collaborators' separate `/courses`, `/steps`, `/records`, and scheduling interfaces were audited but not duplicated where the Portal already has a supported workflow or where authorization differs.

## Implemented presentation and interaction changes

- Added the reference-inspired qualification header, `Student Enrollment` title, active period summary, and explicit `Step N of 4` treatment.
- Reorganized the draft into distinct identity, classification, academic-path, and review stages without changing APIs or validation rules.
- Presented all four existing classification values as accessible semantic choice cards.
- Presented authoritative program and curriculum data as read-only academic-path cards instead of inventing Student-side course selection.
- Presented actual configured requirements as responsive upload tiles while retaining PDF/JPEG/PNG support, the existing 10 MB limit, verified-document reuse, and backend validation.
- Clarified Previous, Continue, Save draft, Submit application, and Cancel draft actions.
- Added protection against accidentally leaving a draft after changing its classification. Browser navigation and route changes ask for confirmation only when the local classification differs from the saved application.
- Added responsive layouts for desktop, tablet, and narrow mobile-browser widths.
- Scoped all new presentation rules under `html.website-ui-2-client`; Android and Electron retain their existing presentation.

## Backend and academic boundaries preserved

No Laravel endpoint, business rule, schema, migration, or database record was changed. The UI continues to use the existing status, application save, document upload/reuse, submit, cancel, subjects, schedule, and COR contracts.

The following reference capabilities were intentionally not fabricated:

- editable personal, birthday, gender, address, contact, or email fields owned by Profile/Admission;
- first and second program choices, static course capacity, or hard-coded slots;
- fixed document requirements that bypass Registrar configuration;
- Firestore status names or direct client persistence;
- fees, payment details, or unsupported official signatures;
- Registrar/Admin Website access or Electron workflow changes.

These require separate backend, data-ownership, authorization, or academic-policy approval. See the parity blueprint for the full field-by-field mapping.

## Files changed for this stage

- `CDM_Frontend/src/modules/enrollment/EnrollmentStudent.vue`
- `CDM_Frontend/src/modules/enrollment/enrollment.css`
- `CDM_Frontend/test/enrollmentReferenceParity.test.js` (new)
- `docs/ENROLLMENT_REFERENCE_PARITY.md` (new)
- `docs/UI_2_ENROLLMENT_REFERENCE_IMPLEMENTATION.md` (new)

Other pre-existing uncommitted files in the working tree were preserved and are not attributed to this stage.

## Validation results

Run on local `dev` on 2026-10-28 without Laravel or MariaDB access:

| Check | Result |
| --- | --- |
| Focused Enrollment, access-control, website UI, and frozen Grading regressions | Passed: 7 files, 0 failures |
| Full frontend suite | Passed: 71 files, 0 failures, 8.453 seconds |
| Website production build with inert `https://example.invalid/api` build-time URL | Passed: 356 modules, 11.07 seconds |
| Android mobile-development build | Passed: 350 modules, 10.30 seconds; PWA generated |
| Electron desktop-development renderer build | Passed: 350 modules, 11.57 seconds; PWA generated |
| `git diff --check` | Passed with no whitespace errors |

Laravel tests were not run because no backend interface or backend implementation was changed. No migrations, seeders, database commands, or development MariaDB connections were used.

The Android and Electron bundles contain the pre-existing shared Enrollment component, but the new high-fidelity presentation selectors require the Website-only `website-ui-2-client` shell and are absent from their compiled CSS. This preserves platform appearance while confirming shared code still compiles.

## Known differences and limitations

- Visual fidelity is based on verified source hierarchy and styling rather than side-by-side runtime screenshots.
- Portal Enrollment deliberately has four stages rather than the reference's three because identity and academic path are authoritative review stages and document requirements depend on the saved classification.
- Program choice, capacity display, finance, and editable personal information remain unavailable until approved contracts exist.
- Final visual behavior, native file-picker behavior, long translated/content cases, and real configured-document combinations require manual browser UAT.

## COR null-schedule repair — 2026-10-28

### Confirmed root cause

`EnrollmentAcademicController::record()` associates each enrolled subject with its matching `SectionSubject` model. A section-subject assignment can validly exist before all schedule fields are populated, so the response may contain a truthy `schedule` object whose `start_time`, `end_time`, or room is `null`.

`EnrollmentCor.vue` previously checked only `s.schedule` and then called `s.schedule.start_time.slice(0, 5)` and `s.schedule.end_time.slice(0, 5)` during render. The reported exception was therefore caused by a present but incomplete schedule assignment—not by the Student identity or term shown in the screenshot.

### Correction

- COR schedule rendering now uses the existing shared `hasSavedSchedule()` predicate, which requires day, start time, end time, and room before time formatting occurs.
- Complete schedules retain the original `HH:MM–HH:MM` presentation.
- A valid subject with no complete optional schedule displays `Schedule unavailable`; no academic values are invented.
- Time formatting occurs only after schedule completeness is established.
- The component validates the essential COR structure and subject load. A malformed record shows an accessible error instead of rendering an apparently official incomplete document, and Print COR is withheld.
- Optional display values such as course/year level, hours, units, and total units use the explicit `Not available` presentation when absent.

No backend endpoint, academic calculation, authorization rule, official COR field, or print style changed.

### Regression coverage and results

`CDM_Frontend/test/enrollmentAcademic.test.js` now reproduces a schedule object with null time values, verifies the safe unavailable-schedule presentation, verifies that a structurally invalid null subject load is rejected, and retains coverage for a fully valid formatted schedule and printing.

| Check | Result |
| --- | --- |
| Focused Enrollment suite | Passed: 3 files, 0 failures, 2.110 seconds |
| Full frontend suite | Passed: 71 files, 0 failures, 7.775 seconds |
| Website production build with inert `https://example.invalid/api` build-time URL | Passed: 356 modules, 12.76 seconds |
| Android mobile-development build | Passed: 350 modules, 13.01 seconds; PWA generated |
| Electron desktop-development renderer build | Passed: 350 modules, 12.86 seconds; PWA generated |

### Mobile review and remaining UAT

Source-level responsive review confirms that academic-record rows collapse to a single column below 720 px, actions become full-width, the Enrollment card stays within its workspace, and the wide official table scrolls inside `.en-table` rather than widening the page. At 390 px and 320 px, the existing 480 px rule also stacks actions and provides full-width touch targets. No confirmed CSS defect was found, so no speculative mobile styling was added.

An interactive browser session and real Student API were not available in this validation pass. Manual review must still verify at 390 px and 320 px:

1. select a finalized record with both scheduled and unscheduled subjects;
2. confirm the academic-record card and View COR button remain inside the viewport;
3. horizontally scroll only the COR table and confirm the page itself does not overflow;
4. confirm Print COR opens the browser print flow and preserves official light document styling;
5. move/dock CDM Buddy near the bottom edge and confirm it does not cover View COR or Print COR;
6. switch terms repeatedly, including empty and API-error states, and confirm stale records do not remain visible;
7. repeat in Light, Dark, and System themes on desktop and mobile widths.

## Manual visual UAT

Use a Student test account against an approved local API/database environment. Do not use presentation or production services.

1. At 1440 px and 1024 px, open `/enrollment/status`; verify the period card, title, four-stage progress indicator, card alignment, and action footer have no overlap.
2. Start or open a draft. Verify Step 1 shows the correct authenticated Student identity, assigned program/curriculum, year level, and active term as read-only values.
3. On Step 2, select each classification with mouse and keyboard. Verify selected, hover, focus-visible, Light, Dark, and System theme states.
4. Save the classification, reload, and verify it persists from the server. Then change it locally and attempt browser back, route navigation, and tab close; verify the unsaved-change warning appears without losing work silently.
5. On Step 3, verify the academic path matches the accepted Admission/current Student record and cannot be edited from Enrollment.
6. On Step 4, verify only Registrar-configured requirements appear. Test PDF, JPEG, PNG, invalid type, over-10-MB file, verified-document reuse, upload progress, failure feedback, and successful attachment.
7. Submit only a complete approved test draft. Verify the existing confirmation, server validation, status history, and application version behavior.
8. Verify rejected/resubmittable, submitted/read-only, approved, enrolled, cancelled, upcoming-period, ended-period, and no-period states.
9. Verify Subjects, Schedule, and COR routes remain correct after navigating from the redesigned application.
10. Repeat layout checks at 768 px, 390 px, and 320 px. Verify no horizontal page overflow, clipped focus rings, covered footer actions, or CDM Buddy obstruction.
11. Build or inspect Android and Electron locally and confirm their Enrollment screens retain their prior styling and navigation.
12. Capture matched screenshots at 1440, 768, and 390 px for final design approval; no automated browser screenshots were available during this implementation.

