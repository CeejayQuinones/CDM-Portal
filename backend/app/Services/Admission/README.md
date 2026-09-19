# Admission service boundary

Future Admission services belong in `App\Services\Admission`.
No exam, publication, recommendation, or import behavior is implemented in Step 1.
Resolve applicant identity and schema ownership in Step 2 before adding models or services.
Reuse existing portal users and roles; do not copy the source authentication systems.
See `docs/ADMISSION_INTEGRATION.md` at the repository root.
