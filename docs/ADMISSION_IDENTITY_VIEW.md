# Admission identity viewing — Step 4

Read-only identity access inside the existing portal. No creation, exam, result,
recommendation, Registrar workflow, import or Student conversion is implemented.
Monitoring and Document Request files/behavior remain unchanged.

## Endpoint and access

`GET /api/admission/me` uses existing Sanctum authentication and Admission's
`viewOwn` policy against the refreshed current User. Active Guests and Students
may read their own identity/history. Professor, Registrar Staff and Admin are
denied this self-service endpoint; inactive users are denied. No listing endpoint
or mutation route is introduced. Caller query IDs do not change ownership scope.

Return the latest own application by `created_at DESC, id DESC`; no row from another
account, no automatic case creation, no invented cycle. The tie-breaker makes
selection deterministic when timestamps match. This is a latest-identity summary,
not a history browser. No status transitions occur on reads.

Successful response shape:

```json
{
  "success": true,
  "data": {
    "has_application": true,
    "application": {
      "applicant_number": "APP-<UUID>",
      "status": "draft",
      "cycle": {
        "code": "<stored code>",
        "name": "<stored name>",
        "status": "open",
        "opens_at": "<ISO timestamp or null>",
        "closes_at": "<ISO timestamp or null>",
        "confirmation_closes_at": "<ISO timestamp or null>"
      },
      "created_at": "<ISO timestamp or null>",
      "submitted_at": null,
      "is_converted": false,
      "converted_at": null
    }
  }
}
```

A real empty history returns HTTP 200 with `has_application: false` and
`application: null`. Payloads exclude internal IDs, source provenance, snapshots,
contact proof, academic Student records, policy JSON, staff IDs and audit data.
The endpoint uses `Cache-Control: private, no-store` for success/unavailable replies.

## Navigation and UI

Guests gain only the Admission Status sidebar item and `/admission` access.
Students retain existing Admission routes. Professor blocking and Registrar/Admin
role-specific placeholders remain intact. Staff's legacy `/admission` URL continues
to show its placeholder without calling the self-service API.

The home page uses existing heading/card/color tokens with a two-column definition
list that collapses to one column on small screens. It shows applicant number,
cycle, status badge, human-readable created/submitted dates, and stored conversion
time only when present. Guidance maps only the eight approved persisted statuses;
it does not infer exam eligibility or advertise creation. Unknown statuses have a
neutral fallback. `/admission/exam`, `/admission/result` and
`/admission/recommendation` remain placeholders and remain unavailable to Guests.

States: Loading admission information..., No admission application yet,
Unable to load admission information, or the stored identity. Retry performs only
another GET. Raw server errors are never displayed. Account changes clear displayed
identity and invalidate older requests; unmounting also invalidates pending replies.
The module service uses the shared apiClient and rejects malformed payloads.

## Migration safety and deployment limit

Both Step 3 migrations were confirmed **Pending** with read-only
`php artisan migrate:status` against the existing MySQL database. The sandbox
connection failed initially; the approved outside-sandbox status check succeeded.
No local migrations, resets, destructive seeders or imports were executed.

Until the two tables are deployed under a separate instruction, the live endpoint
returns HTTP 503 with `Unable to load admission information.` and the page shows
its error state. It does not misreport missing tables as no application. The real
identity/empty experience cannot be used locally until that prerequisite is met.
Database query failures also return the generic 503 and are reported server-side.
Only disposable in-memory test databases receive migrations/test fixtures.

## Verification

Backend tests cover Guest ownership, injected foreign identifiers, safe whitelisted
fields, Student historical access, latest selection, empty history, denied roles,
suspended accounts, anonymous requests, missing-schema 503 and unchanged stored
rows after reads. POST is not an available route.

Frontend tests use the real router, navigation store, Admission service and compiled
Vue components with a mocked shared HTTP transport. They cover all roles, loading,
identity/date display, empty and error states, malformed responses, retry, stale
account responses, mounted placeholders and no staff-home API calls. Browser visual
verification and deployed-schema end-to-end checks are separate from these tests.

## Recommended Step 5

First authorize controlled deployment of the two reviewed Step 3 migrations and
verify real Guest/Student read-only navigation. Application creation remains a
separate increment requiring the previously identified audit foundation and MySQL
concurrency verification. Do not begin exam implementation automatically.
