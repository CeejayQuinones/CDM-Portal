# Enrollment UI 2.0 Phase 1.3 — Reference Parity

## Scope and evidence

Phase 1.3 implements the safe Website Student portions of the collaborators' Enrollment reference while retaining CDM Portal as the source of truth.

- Reference repository: `https://github.com/ayumiswift16-ux/ENROLLMENT`
- Audited revision: `b67543eb30c69548694659689f4e51a1b9d3dcb0`
- Read-only checkout used during implementation: `/tmp/cdm-enrollment-reference`
- Reference files reviewed: `Courses.tsx`, `Dashboard.tsx`, `Enroll.tsx`, `Steps.tsx`, and `constants.ts`
- Target: Website Student UI only

No reference Firestore data, hard-coded capacities, program descriptions, tuition claims, or administrative behavior was copied. The reference application was not run and no rendered before/after screenshots were captured, so visual acceptance remains manual.

## Reference-to-Portal matrix

| Reference behavior | Reference reality | Phase 1.3 Portal result | Authority |
| --- | --- | --- | --- |
| Courses Offered | Static three-column cards with hard-coded programs, descriptions, durations, modes, and slot counts | Responsive cards populated only from active Portal courses and curricula | New authenticated, Student-only read contract |
| View Curriculum | Visible action without a handler | Working route to a verified curriculum and subject table | Active Course, Curriculum, Subject, and Semester records |
| Dashboard enrollment summary | Firestore-backed status/deadline cards | Website Student hero uses the existing enrollment status response | Existing `/enrollment/status` contract |
| Three-step application | Student info, Enrollment, Course | Existing Phase 1.2 three-step form retained unchanged | Existing versioned Enrollment workflow |
| Six-stage guide | Static cards; Learn Requirements has no handler | Six clearly informational cards with working Portal links | Existing Admission, Enrollment, Subjects, and COR routes |
| Program selection | Static first/second choices | No new program-selection rule; verified program remains read-only | Admission/Student academic record |
| Capacity/slots | Hard-coded | Omitted and disclosed as unavailable | No approved Portal data source |
| Tuition/payment | Mentioned in reference records flow | Explicitly not represented | No Portal finance contract |

## Implemented Website pages

### Courses Offered

`/enrollment/courses` lists active academic programs with their configured code, title, department, duration, and active curriculum count. The page includes loading, retry, and empty states. Program descriptions, study mode, and capacity are labeled unavailable rather than inferred.

### Program curriculum

`/enrollment/courses/:courseId/curriculum` resolves the selected active program, supports multiple active curricula, groups subjects by year level and semester, and displays configured units. It provides explicit states for an unknown program, no active curriculum, or no active subjects.

### Enrollment guide

`/enrollment/steps` explains the wider six-stage journey while clearly separating it from the three-step application form. Every action maps to an existing authorized Portal route. It does not promise a general interview, payment processing, program self-assignment, section self-assignment, or COR access before finalization.

### Student dashboard

The Website Student dashboard now includes:

- an enrollment-focused welcome and direct actions;
- current period state and configured close date from the status API;
- a three-point summary of submitted, Registrar assessment, and finalized enrollment;
- quick links to Courses Offered and the Enrollment Guide.

The progress indicator is explanatory and does not create or mutate application status. It deliberately labels the middle stage “Registrar review” to avoid presenting a separate assessment business rule.

## API contract added for review

`GET /api/enrollment/academic/catalog`

- Authentication: Sanctum and the existing Enrollment boundary.
- Authorization: active Student role only; staff and Professors are rejected.
- Mutation: none.
- Response: active academic year; active programs; active curricula; active subjects and semester grouping; explicit availability metadata.
- Excluded fields: slots/capacity, study mode, descriptions, fees, and unpublished or inactive records.

This is an additive read-only contract and requires normal API/security review before rollout. It adds no migration or schema change.

## Platform isolation

The three new routes declare `platforms: ['web']` and Student-only route roles. Courses Offered and Enrollment Guide appear only in the Website Student navigation. Android and Electron continue using their existing presentation and authorization boundaries. Shared compilation can include lazy source chunks, but those clients cannot authorize or navigate to the new routes.

## Files changed by Phase 1.3

### Frontend

- `src/config/accessControl.js`
- `src/router/index.js`
- `src/modules/dashboard/StudentDashboardView.vue`
- `src/modules/dashboard/StudentWebsiteDashboard.vue`
- `src/modules/dashboard/dashboardService.js`
- `src/modules/dashboard/websiteDashboard.css`
- `src/modules/enrollment/EnrollmentCourses.vue` (new)
- `src/modules/enrollment/EnrollmentCurriculum.vue` (new)
- `src/modules/enrollment/EnrollmentSteps.vue` (new)
- `src/modules/enrollment/enrollmentCatalog.js` (new)
- `src/modules/enrollment/enrollmentCatalogPresentation.js` (new)
- `src/modules/enrollment/enrollment.css`
- `test/enrollmentAcademic.test.js`
- `test/enrollmentPhaseOneThree.test.js` (new)
- `test/websiteSidebarStepOneOne.test.js`

### Backend

- `app/Http/Controllers/Api/Enrollment/EnrollmentAcademicController.php`
- `routes/api.php`
- `tests/Feature/EnrollmentAcademicTest.php`

### Documentation

- `docs/ENROLLMENT_REFERENCE_PARITY.md`
- `docs/ENROLLMENT_UI2_PHASE1_3_REFERENCE_PARITY.md` (new)

## Automated validation — 2026-10-29

| Check | Result |
| --- | --- |
| Focused Enrollment frontend and navigation regressions | Passed, 4 files |
| Full frontend suite | Passed, 76 files |
| Focused Laravel Enrollment suite | Passed, 24 tests / 365 assertions |
| Full Laravel suite, SQLite `:memory:` | Passed, 477 tests / 4,570 assertions |
| Website production build | Passed with inert `https://build.invalid/api` compile-time URL |
| Android mobile-development build | Passed |
| Electron desktop renderer build | Passed with inert `https://build.invalid/api` compile-time URL |
| Laravel Pint (touched PHP) | Passed |
| `git diff --check` | Passed |

No migration, seeder, MariaDB operation, `.env` edit, deployment, or hosted-service call was performed.

## Manual UAT checklist

- Sign in as a Student on the Website and confirm Courses Offered and Enrollment Guide appear under Enrollment.
- Confirm Guest, Professor, Admin, Registrar, Android, and Electron clients cannot open the new routes.
- At 1440, 1024, 768, 390, and 320 px, inspect card wrapping, curriculum table scrolling, text readability, keyboard focus, and CDM Buddy clearance.
- Check Light, Dark, and System themes on all three pages.
- Verify each program shows only configured data and that missing descriptions, study mode, duration, curricula, subjects, and academic year remain honest empty states.
- Open every View Curriculum and journey action; confirm no dead links or wrong-role destinations.
- Compare dashboard period/status/progress with the same Student's Enrollment Status record.
- Run the existing three-step application through draft, document upload, submission, correction, and read-only finalized states.
- Confirm Subjects, Schedule, and COR still use only finalized academic records.
- Confirm the official COR print layout is unchanged.

## Remaining limitations

- Browser-rendered visual UAT and screenshots were unavailable in this implementation session.
- CDM Portal has no approved Student-visible capacity, slot, study-mode, or program-description source.
- It has no Student first/second program preference contract, finance workflow, or general Enrollment interview scheduler.
- Catalog publication is currently represented by active record status; a separate public catalog publication workflow would require approval.
- Registrar Website workflows remain intentionally absent; administrative Enrollment stays in the authorized desktop workspace.

