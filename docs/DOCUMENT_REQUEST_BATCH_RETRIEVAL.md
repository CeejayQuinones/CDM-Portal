# Multi-document requests and physical retrieval

## Existing architecture and additions

`document_requests` remains the individual, independently processed record. New submissions may share a `document_request_batch_id`; older single requests have a null batch ID and remain accessible through the existing endpoints. A batch's `GROUP-######` reference is derived from its database ID. The Student API returns only their own requests and group reference, never physical storage locations.

The existing physical inventory assigns at most one `student_record_locations` row to a student. It points to a `cabinet_slots` row and its cabinet. The Physical Records UI calls these slots **drawers**. There is no separate slot-within-drawer entity; the retrieval queue uses the actual cabinet and slot/drawer code and does not invent a finer location.

`document_types.requires_physical_retrieval` is Registrar-configurable and defaults to false for every existing type. The setting is exposed in Registrar Document Type Management only. Changing it does **not** backfill old requests. New single or grouped requests for an enabled type attach to one active task per student. The nullable unique `active_student_id` key prevents duplicate active folder tasks; a returned task clears it, allowing a later task while preserving history. The pivot table records which individual requests need the folder. The queue is Registrar-only and paginated. Awaiting rows use the student's current location; retrieval snapshots the cabinet and drawer code into immutable audit events. A missing location remains visible and blocks marking the folder retrieved.

## Workflow boundaries

- Student cart submissions validate every type, copy count, and purpose, then create the group and all individual requests in one transaction. A per-student UUID idempotency key plus payload hash makes an identical retry return the original batch and rejects key reuse with different content.
- Each document continues through the existing appointment, approval, claim-code, cancellation, and completion workflow. Group selection does not approve or release all members.
- The only bulk status action added is rejection of selected **pending** requests with a required reason. All selected rows are locked and checked before any change; the existing per-request rejection workflow and audit execute inside one outer transaction.
- Retrieval is an internal folder movement aid, not a document status, payment, or approval step. Returning a folder does not change the underlying document requests.

## Migration and rollback

New migrations, in order:

1. `2026_11_11_000001_create_document_request_batches.php`: creates `document_request_batches` with a student foreign key, idempotency key, payload hash, unique `(student_id, idempotency_key)`, and student/date index; adds nullable batch foreign key and batch/status index to `document_requests`.
2. `2026_11_11_000002_create_physical_retrieval_queue.php`: adds the default-false document-type flag; creates task, request-link, and event tables with foreign keys and status/date indexes. Active task uniqueness uses a nullable unique student key, compatible with SQLite and MariaDB's allowance of multiple nulls.

Neither migration has been run against an existing database. Take and restore-test a database backup before separate migration approval. Run only these migrations in order after checking dependencies and pending migrations. Do not use `migrate:fresh` or a blanket migration command on a populated database. The `down()` methods refuse to discard group history, retrieval history, or enabled retrieval configuration. Rollback after real use therefore requires a separately approved archival/data-conversion plan; reverting application code alone while retaining additive columns is safer than dropping history.

## Manual UAT still required

1. On Website and Android, add two types, remove/re-add one, set shared and overridden purposes, submit, retry after simulated response loss, and verify exactly one group and one request per selected type.
2. Verify existing single-request history and its Registrar-managed appointment/claiming flow.
3. In Electron Registrar, filter and expand groups; process one document and confirm siblings remain unchanged. Select multiple pending items for rejection; confirm the audit reason and atomic failure behavior.
4. Confirm default-off retrieval settings for existing types, enable one test type, submit new requests, and verify old requests are not backfilled.
5. With two requests for the same student folder, verify one task; move a test record, retrieve, and return it. Confirm the current location is used before retrieval and prior event snapshots remain unchanged.
6. Test missing locations, keyboard focus, Light/Dark themes, narrow Electron windows, Android layout, and network errors. No rendered visual acceptance or live MariaDB UAT has been claimed.

## Current limitation

The existing Student request-list endpoint returns the student's full history as an array because the current Student dashboard derives appointments and counters from that contract. Registrar group and retrieval endpoints are paginated and eager-loaded. Changing Student history pagination needs a separate coordinated contract update if real account history becomes large.
