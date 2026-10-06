# Grading Phase 3 Student experience

## Published-grade visibility and official source

Students see a grade only when its grade sheet is `published` and the Student exists in that sheet's latest immutable submission snapshot. Draft, Returned, Submitted, and Approved sheets remain private. Visibility never depends on the Student's current Section.

The official Student result is read from `grade_submission_students` through the latest `grade_submission_attempts` row. Phase 3 does not recompute a released result from mutable assessments. New submissions include a context snapshot inside the checksummed submission configuration: academic year, semester, course, year level, section, subject code/name/units, and the sheet's Professor. This preserves historical presentation after later catalog, Section, or assignment changes. Older Phase 2 attempts without this context remain readable through their current academic foreign keys and are labeled internally as legacy current-reference context.

The Student My Grades page provides term selectors, published grade rows, total enrolled units, published-versus-expected subject counts, neutral completion state, CSV Grade Report export, and a subject-specific Professor conversation. Grade rows show subject, Professor, historical Section, units, Midterm, Finals, final numeric grade, grade point, remarks, and publication date.

## GWA and completion policy

The repository has no approved institutional grade-point mapping or numeric-grade GWA policy. Phase 3 therefore does not convert numeric grades to grade points and does not calculate a GWA. The API returns:

- `available: false`
- `value: null`
- `Not available until grading scale is configured.`

`grade_point` and `remarks` remain `null` and the UI displays `N/A` or `Not configured`. No Passed, Failed, Dean's List, Probation, or similar standing is inferred.

Term completeness compares published snapshot rows with applicable `enrollment_subjects` from finalized `enrollments` for the selected academic year and semester. Subjects with `enrolled` or `completed` status are expected. The neutral states are `Complete`, `Incomplete`, and `No published grades`. A partial term is always labeled Incomplete; no provisional GWA is presented. The existing authoritative `students.student_status` is displayed separately without reinterpretation.

## Registrar grade history

Student Management remains the Student repository. Student Profile → Admission and Enrollment → Grades loads the same Published-only service used by Students, with academic-year and semester filters. Registrar Staff and Admin can inspect official rows and export that Student's Academic Grade Summary. The Grading review detail also exports Approved or Published class snapshots as CSV.

Exports include safe academic metadata and numeric results. They omit database IDs, checksums, audit metadata, credentials, and unrelated personal information. Student export is limited to the authenticated Student's own history. Staff exports use Registrar/Admin authorization. Export endpoints are rate-limited, private/no-store, and audited as `grade_report.exported`.

## Grade messages

`grade_conversations` binds one Student and the sheet's frozen Professor to one published grade sheet. `grade_messages` stores the sender, optional text, optional private image metadata, server-side `read_at`, and `unsent_at`. Registrar Staff and Admin do not receive conversation access.

A Student can create a conversation only when they appear in that Published submission. The Student and the Professor stored on the grade sheet are the only participants. Opening a thread marks unread messages from the other participant as read, so counts persist across devices. A sender may unsend only their own message. Unsend keeps a tombstone, removes text and attachment metadata, deletes the stored file, and records `grade_message.unsent`.

Attachments accept only validated JPEG, PNG, WebP, or GIF images up to 5 MB. Files receive generated names under private Laravel storage. Authenticated participant authorization is rechecked before every image response. Responses use `private, no-store`, MIME controls, `nosniff`, and a restrictive content-security policy. Arbitrary documents and executable files are rejected.

Message creation, read transitions, and unsend record `grade_message.created`, `grade_message.read`, and `grade_message.unsent`. Export records `grade_report.exported`.

## Platform and immutable publication rules

- Student: Web and Mobile; own Published grades and own conversations only.
- Professor: Web; conversations for their own frozen grade-sheet identity only.
- Registrar Staff: Desktop; Published Student history and Approved/Published exports.
- Admin/System Admin: Web or Desktop; the same staff grade-history/export access.
- Guest: no grading access.

Every endpoint remains under Sanctum authentication and `client.platform`. Role middleware protects Student, Professor, and staff surfaces, while conversation services independently verify participant ownership. Published sheets remain read-only. Phase 3 does not invent a post-publication correction workflow.

## Final end-to-end workflow

Registrar sets grading windows
→ Professor enters assessment scores
→ Professor submits an immutable snapshot
→ Registrar/Admin reviews
→ sheet is Returned or Approved
→ Registrar/Admin creates or executes a release schedule
→ Approved sheet becomes Published
→ Student views the official snapshot
→ Student may open an optional grade-concern conversation with the sheet's Professor.

This completes the Grading module. A future institutional policy may configure grade points, remarks, and GWA, but that policy is not inferred by this implementation.
