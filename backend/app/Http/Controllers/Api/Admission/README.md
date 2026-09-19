# Admission HTTP boundary

Future Admission controllers belong in `App\Http\Controllers\Api\Admission`.
Reuse the portal authentication and role middleware. Delegate Admission work to
`App\Services\Admission`; enforce authorization and applicant ownership on the server.

Step 1 registers no endpoints, controllers, models, or database tables. Existing
Student, Registrar, Document Request, and Monitoring controllers remain unchanged.
See `docs/ADMISSION_INTEGRATION.md` at the repository root for scope and source findings.
