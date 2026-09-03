# CDM Portal performance maintenance

## Development diagnostics

Development builds and production builds made with `--mode demo` expose a data-safe monitor at
`window.__CDM_PERFORMANCE__`. Call `window.__CDM_PERFORMANCE__.snapshot()` in developer tools to inspect:

- application mount and route-settle durations;
- request count, status, duration, and response byte estimate;
- the slowest request and largest response on the current route;
- duplicate identical requests within one second; and
- IndexedDB operation/table duration and returned record count.

Query values, numeric path identifiers, request bodies, headers, credentials, tokens, and response contents are not
logged. API operations over 500 ms and IndexedDB operations over 100 ms are warnings. The monitor is inactive in a
normal production build and has no UI panel.

## Development targets

- Offline or cached page: under 1 second.
- Ordinary local/LAN API request: 300–500 ms.
- Route transition with data already available: 200–300 ms.
- No duplicate initial requests.
- No unpaginated large dynamic datasets.

These are diagnostic budgets. They must not be met by delaying requests, faking loading state, weakening backend
authority, or caching dynamic workflow state.

## September 2026 baseline and changes

The local profiling dataset contained 5,001 students, 25,003 document requests, and 10,005 appointments. Before the
pass, the production entry JavaScript was 420.61 kB (131.29 kB gzip) and the global CSS was 687.14 kB (99.64 kB gzip).
All route components were eagerly imported.

After route code splitting, the production entry JavaScript is 201.63 kB (73.83 kB gzip) and global CSS is 2.58 kB
(1.06 kB gzip); page-specific code and styles load with the selected route. Student Management now starts with one
request instead of two: the 38,129-byte bulk-options response is fetched only after a student is selected. Active
document types use a five-minute in-memory cache that is invalidated by registrar document-type mutations.

The IndexedDB schema is version 2. Student, request, appointment, physical-record, and blocked-date indexes support
the demo's observed lookup patterns. Student demo hydration uses student-scoped indexed reads, and ID allocation uses
a reverse primary-key cursor instead of `getAll()` plus a maximum scan. Existing seed data is version-marked without
clearing or reseeding user-created records.

No MySQL indexes were added. `EXPLAIN` on the main registrar request and appointment queue shapes selected the existing
`doc_requests_updated_id_idx` and `appointments_updated_id_idx` indexes and estimated only 20 and 50 scanned index rows,
respectively. Adding status/order variants would have been redundant for the observed queries.
