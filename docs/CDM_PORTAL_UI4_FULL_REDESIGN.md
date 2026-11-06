# CDM Portal UI 4.0 — audit, page inventory, and phased redesign

## Reference audit (30 October 2026)

The collaborators' [ENROLLMENT repository](https://github.com/ayumiswift16-ux/ENROLLMENT) was inspected at `main` tree `b67543eb30c69548694659689f4e51a1b9d3dcb0`. Its [tokens](https://github.com/ayumiswift16-ux/ENROLLMENT/blob/main/src/index.css) use Inter, a slate canvas (`#f8fafc`), white bordered surfaces, dark slate text, a deep green rail (`#051c14`), emerald (`#059669` / `#10b981`), and compact borders and shadows. [Cards](https://github.com/ayumiswift16-ux/ENROLLMENT/blob/main/src/components/ui/Card.tsx) have modest radii, a pale header band, a divided content body, and a clear footer. [Buttons](https://github.com/ayumiswift16-ux/ENROLLMENT/blob/main/src/components/ui/Button.tsx) are compact with visible focus states. [Courses](https://github.com/ayumiswift16-ux/ENROLLMENT/blob/main/src/pages/Courses.tsx) use a program grid with metadata and a curriculum action; [Steps](https://github.com/ayumiswift16-ux/ENROLLMENT/blob/main/src/pages/Steps.tsx) use numbered journey cards; [Enroll](https://github.com/ayumiswift16-ux/ENROLLMENT/blob/main/src/pages/Enroll.tsx) uses a three-step identity/type/program flow; [Scheduling](https://github.com/ayumiswift16-ux/ENROLLMENT/blob/main/src/pages/Scheduling.tsx) offers card, timetable, and list views. [Records](https://github.com/ayumiswift16-ux/ENROLLMENT/blob/main/src/pages/Records.tsx) uses searchable tables and contextual detail panels.

The reference is React/Firestore, with sample counts, fees, slot capacity, and stages CDM Portal does not authorize. Those data and workflows are **not** copied. CDM Portal keeps its Laravel APIs, verified institutional seal, role rules, published-grade rules, and official print content. The reference provides page composition and visual rhythm, not business policy.

## Actual route inventory and backlog

| Platform / role | Actual routes and modules | Phase and page work |
| --- | --- | --- |
| Website Student | `/student-dashboard`; `/enrollment/status`; `/enrollment/courses`; `/enrollment/courses/:courseId/curriculum`; `/enrollment/steps`; `/enrollment/subjects`; `/enrollment/schedule`; `/enrollment/cor`; `/grading`; `/monitoring`; `/monitoring/study-plans`; `/document-requests`; `/settings`; `/admission` (historical status only) | **A:** dashboard and Enrollment flow, verified catalog/curriculum, subjects, schedule, COR, published grades, profile/settings consistency. Monitoring and document requests receive focused page parity after their existing UX review; no separate Student Records route exists. |
| Website Guest | `/guest-dashboard`; `/admission`; `/admission/exam` (Website only); `/admission/result`; `/admission/recommendation`; `/enrollment/status` after admitted handoff | **B:** Admission application/status/result and exam presentation, admitted Guest enrollment handoff. No Guest Settings route exists. |
| Website Professor | `/professor-dashboard`; `/enrollment/teaching`; `/grading`; `/grading/submission`; `/monitoring`; `/monitoring/adviser-alerts`; `/professor/settings` | **B:** assigned classes, schedules, submission and related records. Grading Phase 5.2 internals are frozen. |
| Electron Registrar/Admin | `/registrar-dashboard`, `/admin-dashboard`, `/registrar/settings`, `/campus-notices`, `/registrar/professors`, `/admin/registrars`, Registrar/Admin Admission routes, `/enrollment/applications`, `/enrollment/periods`, `/enrollment/sections`, `/enrollment/subject-management`, `/enrollment/scheduling`, `/enrollment/records`, `/student-management` and detail/documents/reports/physical records, Registrar document requests/appointments/history/types, `/grading` and `/grading/student-history`, monitoring routes | **C:** redesign content of each authorized workspace, retaining Desktop UI 3.0 rail/panel/header. |
| Android Student/Guest/Professor | Their existing authorized dashboard, Enrollment, published-grade, monitoring, document, and profile routes. Website-only catalog/guide, Professor Settings, and examination taking are excluded by route/platform guards. | **D:** touch and safe-area presentation of authorized routes only; no mobile exam taking. |

The labels “Examination Schedule”, “Application Status”, and “Academic Records” are views or states inside existing routes rather than independent pages. No extra route is introduced to match the reference.

## Concrete visual and interaction findings before Phase A

- The Student dashboard already has a distinctive enrollment hero, official-notice area, and working quick actions. Replacing it with the reference's sample dashboard would introduce false data.
- Courses and the journey already approximate the reference composition, but their card radii, shadows, typography, and vertical density differ from its restrained bordered surfaces. The journey's Admission link can send an active Student to a role-restricted flow.
- The Enrollment application has the approved three-step form and verified data. Its history is a flat action row; the current action and historical attempts need clearer separation. Cancelled drafts must remain visible while a new action is available.
- Subjects, schedule, and COR share a selector that presents verified terms as flat rows and appends the selected detail below without a strong record context. On a narrow screen the view switcher, record action, and table compete for width.
- The schedule's three views and print action already work, but class cards have weak hierarchy for day/time, room, professor, and unscheduled state. Official COR content and print styling must remain stable.
- Published grades have an authoritative GWA/completion summary and export; the wide result table needs a clear read-only context, term selection, and horizontal scroll affordance. The grade conversation is separate from Professor Grading internals.
- Settings, profile avatar, and Student dashboard were recently rebuilt. Phase A should align their page-level rhythm without rebuilding account behavior or the global shell.

## Shared design system and Phase A implementation

The existing `--website-*` light/dark/system tokens and approved seal remain the foundation. Phase A added a compact `WebsiteAcademicJourneyNav` and `WebsiteAcademicRecordCard`. Both use semantic tokens, scoped styles, visible focus, and a 44px mobile target. The journey renders only for authenticated Website Students; it does not appear on Android/Electron or for Guest/Professor. The record card separates verified term, status, and action without inventing academic content. Existing Website empty/loading/error components remain in use.

Phase A connected Enrollment, verified programs/curriculum, guide, subjects, schedule, COR, and published grades through the academic journey. Application history and academic-record selection use contextual cards rather than flat action rows. The selected record has an explicit close action. Schedule cards now group subject, day, time, room, and professor; the timetable/list/print behavior is unchanged. Published grades have an explicit read-only, released-results context. The guide's active-Student Admission link was removed because the role guard redirects Students away from that page. The approved three-step application, API paths, calculations, and official COR body/print content were not changed.

| Website Student page | Phase A disposition |
| --- | --- |
| Dashboard | Retained the existing verified enrollment hero, notices, and quick actions; no fake counts or new data. |
| Enrollment Status/application | Added journey and card-based application history; retained form, submission, and cancellation behavior. |
| Courses Offered / curriculum | Added journey and kept the existing verified-catalog program grid and curriculum groups. |
| Enrollment Guide | Added journey and repaired the restricted Admission target. |
| Subjects / Schedule / COR | Rebuilt record selection; schedule cards have a clearer day/time/room/faculty hierarchy. Official COR content is untouched. |
| Published Grades | Added journey, released-results context, and read-only label; retained the authoritative summary, filters, export, and mobile table view. |
| Monitoring / AI Help, Document Requests, Settings | Audited as existing Website-specific workspaces with loading/error/empty states and account-scoped behavior. No speculative changes were made without rendered evidence; these remain explicit visual-review targets. |

Files added: `CDM_Frontend/src/components/website/WebsiteAcademicJourneyNav.vue`, `CDM_Frontend/src/components/website/WebsiteAcademicRecordCard.vue`, `CDM_Frontend/test/uiFourStudentPhaseA.test.js`, and this document. Phase A modifications: `EnrollmentStudent.vue`, `EnrollmentCourses.vue`, `EnrollmentCurriculum.vue`, `EnrollmentSteps.vue`, `EnrollmentAcademicStudent.vue`, `EnrollmentSchedule.vue`, `StudentGradesView.vue`, `test/enrollmentAcademic.test.js`, and `test/enrollmentPhaseOneThree.test.js`. These are Phase A edits within an already-dirty worktree; other Git modifications belong to preceding work and were preserved.

Phase B covers Guest/Professor, Phase C Registrar/Admin, and Phase D Android. None was started. Professor Grading internals remain frozen.

## Enrollment migration prerequisite

The reapplication implementation is complete locally, but its [migration](../backend/database/migrations/2026_10_29_000001_allow_reapplication_after_cancelled_enrollment_draft.php) is pending separate approval and has not been applied to development MariaDB. UI 4.0 must not imply that live data has already crossed that schema boundary. Details are in [ENROLLMENT_REAPPLICATION.md](ENROLLMENT_REAPPLICATION.md).

## Validation and visual review

Focused Enrollment/grades/Phase A tests passed. Full frontend suite: **77/77 test files passed**. Website production build passed using inert `VITE_API_BASE_URL=https://build.invalid/api`; Android mobile-development build passed; Electron renderer production build passed using inert `VITE_DESKTOP_API_BASE_URL=https://build.invalid/api`. The existing `import.meta` CJS test-harness warning remains non-fatal. `git diff --check` passed after Phase A edits. No Laravel or database command was run because this is a frontend-only phase. No hosted service was contacted.

No browser executable or screenshot harness is available in this workspace, so pixel-level fidelity, rendered focus behavior, and mobile overflow **have not been visually accepted**. Browser UAT must cover Website Student at **1440, 1024, 768, 390, and 320px**, each in Light, Dark, and System. Check dashboard → enrollment → catalog/guide → subjects/schedule/COR → published grades; opened and closed records; all three schedule views; long names and missing room/professor; status/loading/error/empty states; keyboard focus and horizontal nav; Buddy overlap; COR print preview; and account/role changes. Confirm the existing Monitoring, Document Requests, and Settings workspaces visually align with the new academic pages before approving Phase A. Electron/Android builds verify compilation and route isolation, not their rendered appearance; visual acceptance remains for Phases C/D.

Recovery checkpoint created before Phase A at `/tmp/cdm-ui4-phasea-prechange.5RR2tX`: `tracked.patch` SHA-256 `34389153d9ea0344a2574c2533e9aaa06477071948c450b4c1b338e6c656a1d7`; `untracked.tar.gz` SHA-256 `9e7f60681ac21f5d8f6f7681d87eda2e1f0593c47aff2aa9cd98c71819e29b9f` (168 archived entries). Existing `.local-recovery` checkpoints and all stashes were preserved.
