# Admission HTTP boundary

`AdmissionIdentityController` serves only `GET /api/admission/me` under the existing
Sanctum authentication. The Admission viewOwn policy restricts it to active Guest
and Student accounts; the query is scoped to the authenticated User relationship.
It never calls identity creation or accepts caller-selected ownership identifiers.

The endpoint returns an explicit safe payload, not a serialized model. Missing
Step 3 tables return generic HTTP 503 without applying migrations. Future staff
and mutation endpoints require separately authorized work.
