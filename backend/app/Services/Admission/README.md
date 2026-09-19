# Admission service boundary

`AdmissionIdentityService` owns Step 3 application identity creation and lookup.
It accepts an existing User and cycle, not request attributes. It rechecks the
persisted active Guest role, profile, lack of an academic Student record and open
intake window. User/cycle locks, database uniqueness and bounded UUID collision
retries protect creation. Use this service for future identity entry points.

No endpoints are exposed. Registrar decisions, exams, results, recommendations,
conversion and imports remain unimplemented. Existing portal auth owns credentials.
See `docs/ADMISSION_IDENTITY_FOUNDATION.md` at the repository root for validation,
authorization and the exact schema scope.
