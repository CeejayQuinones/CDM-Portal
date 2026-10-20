# Admission integration — Step 1

## Scope and ownership

Step 1 adds navigation and unavailable placeholder pages in the existing
DashboardLayout. No endpoints, tables, models, applicant state, exam engine,
recommendation logic, or separate admin application/authentication are introduced.
Monitoring and Document Request behavior are unchanged.

- `CDM_Frontend/src/modules/admission/routes.js`: Admission route records.
- `AdmissionView.vue` and `components/AdmissionPlaceholder.vue`: shared placeholders using existing styles.
- `services/`: reserved API adapter boundary; no requests in Step 1.
- `backend/app/Http/Controllers/Api/Admission/`: future HTTP ownership.
- `backend/app/Services/Admission/`: future application services.
- `src/config/accessControl.js`: existing role and navigation authority.

Add student/registrar/admin view subdirectories when real views need them.

## Access and routes

| Existing role | Page | Path |
| --- | --- | --- |
| Student | Admission Status | `/admission` |
| Student | Entrance Exam | `/admission/exam` |
| Student | Result | `/admission/result` |
| Student | Recommendation | `/admission/recommendation` |
| Registrar Staff | Applicants | `/registrar/admissions` |
| Registrar Staff | Exam Results | `/registrar/admissions/results` |
| Registrar Staff | Review / Publish | `/registrar/admissions/review` |
| Registrar Staff | Admission History | `/registrar/admissions/history` |
| Admin | Question Bank | `/admin/admissions/questions` |
| Admin | Program Configuration | `/admin/admissions/programs` |

The existing named `/admission` landing remains accessible to Registrar Staff and
Admin for saved-link compatibility. It contains only a placeholder; their sidebar
entries use their own routes. Other Admission pages are restricted to the role
listed above. Guest and Professor have no Admission access. Anonymous visitors use
the existing sign-in redirect. Future endpoints must enforce roles and record
ownership independently of frontend guards.

## Source reviewed

Reference: `F:\frm_Chrome\CAPSTONE 1\AMISSION\cdm-career-recommendation-system`
(accessible under `/mnt/f/frm_Chrome/CAPSTONE 1/AMISSION/`).
Read `docs/OPERATIONS.md`, current `backend/routes/api.php`, `ExamSessionService`,
`ResultReviewController`, `CourseController`, `ExamResult`, and the admin router.
The old README is not the behavior contract.

Current code confirms:

- Server sessions own a 120-minute deadline, question/answer-key snapshot and at
  most two submitted attempts. Retakes depend on a published failure. The bank
  selects 20 active questions from each of five categories with randomized order.
- Approval and publication are separate. Corrections and spreadsheet preview/commit
  use version checks. Audit history and notifications need their own persistence.
- Program configuration exists in current course-management routes, so an Admin
  placeholder is included. Portal permissions follow the requested Admin mapping,
  rather than copying source `admissions_staff` permissions.
- The source has separate Student/Admin identities and staff capability names.
  These must not replace portal User authentication or introduce duplicate roles.

## Step 2 decisions (not implemented)

First agree the applicant identity relationship to existing portal users/students,
including how pre-enrollment applicants obtain the requested Student access.
Decide Admission-owned tables and foreign keys without repurposing existing student
or Monitoring records. Map source capabilities to Registrar Staff and Admin.
Define result visibility, immutable attempts/snapshots, audit history,
publication/version semantics, and program-catalog ownership before migrations or
imports. Keep internal score evidence/reasons out of future student responses.
Do not copy the source schema or authentication tables wholesale.

Placeholders promise no eligibility, scheduled exam, result or working action.
Step 2 requires a separate instruction.
