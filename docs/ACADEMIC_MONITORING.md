# Academic Monitoring

## Phase 1, Phase 2, and Phase 3 scope

Academic Monitoring Phase 1 provides a read-only academic risk overview for:

- Students viewing only their own academic record
- Professors viewing only students in section-subject assignments they currently own
- Registrar Staff viewing the institution-wide overview through the Desktop client
- Admin users viewing the institution-wide overview through the Web or Desktop client

Guest accounts cannot access Monitoring. The shared **auth:sanctum** and **client.platform** middleware still applies, and the Monitoring middleware requires an active account.

Phase 1 does not create or update academic records. Phase 2 adds advisory Study Plans, computed Adviser Alerts, and staff-to-Student academic support notices. Phase 3 adds optional AI Help and a deterministic fallback academic coach. None of these phases changes grades, enrollment, passing decisions, academic standing, or the Phase 1 risk calculation.

## Authoritative source

The read path is:

1. **students**
2. finalized **enrollments** (enrolled or completed)
3. active **enrollment_subjects** (enrolled or completed)
4. **grade_sheets** with status exactly published and a publication timestamp
5. each sheet's latest **grade_submission_attempt**
6. the matching immutable **grade_submission_students** row

The snapshot supplies the official midterm, finals, and final values. Monitoring does not read live **grade_scores**, draft grading workspace data, or deprecated **grades** / **grading_periods**.

The following data is deliberately excluded:

- Draft, Submitted, Returned, and Approved Grade Sheets
- live Professor score entries and assessments
- legacy **grades** and **grading_periods**
- Admission examination scores
- Event attendance

Phase 1 assessments remain computed read-only from current authoritative records. Phase 2 adds only notice context, a duplicate-lookup index, and an append-only intervention audit table.

Monitoring is advisory only. It does not determine pass/fail. It does not calculate GWA. It does not modify grades.

## Scope enforcement

Student scope starts from the authenticated user's **students.user_id** and cannot select another Student.

Professor scope requires all of the following:

- an active Professor identity for the authenticated user
- a current **section_subjects** assignment owned by that Professor
- a finalized Enrollment in that Section
- an **enrollment_subjects** row for the same Subject and Professor
- a Published Grade Sheet owned by that Professor for any displayed official result

Registrar Staff and Admin use the same institution-wide academic data scope. Their client access remains different under the platform policy: Registrar Staff use Desktop, while Admin may use Web or Desktop.

## Risk model

The engine is deterministic and institution-neutral. It does not define a passing grade, failing grade, GWA cutoff, probation rule, or honors rule.

For each Subject:

- Trend compares Finals with Midterm inside the same official Subject snapshot.
- A movement of at least 5 points is labeled Improving or Declining. Smaller movement is Stable.
- A decrease of at least 10 points is a Moderate signal.
- A decrease of at least 20 points is a High signal.
- When a published class snapshot contains at least four results, a result in the lower quarter is a Moderate signal.
- A result that both decreases by at least 10 points and is in the lower quarter is a High signal.

For the Student:

- High means at least one High subject signal or at least two Moderate subject signals.
- Moderate means exactly one Moderate subject signal.
- Stable means published data exists without either condition.
- Insufficient data means no Published official snapshot is available for the selected term.

These bands identify material change and relative class position. They are monitoring signals, not academic decisions. Every response includes plain-language reasons, Subjects analyzed, expected versus published Subject counts, and the methodology text shown in the UI.

## Data completeness

- **complete**: every expected finalized Enrollment Subject in the selected term has a matching Published official snapshot
- **partial**: at least one, but not every expected Subject, has a Published snapshot
- **no_published_data**: no expected Subject has a Published snapshot

Missing results remain missing. The API never synthesizes a grade or substitutes an average.

## API and UI

The Phase 1 endpoint is:

**GET /api/monitoring/overview**

The existing **early-warnings** and Student **my-risk** routes use the same Phase 1 engine for compatibility.

Optional filters:

- **academic_year_id**
- **semester_id**
- **search** (staff and Professor list)
- **risk**: high, moderate, stable, or insufficient

The responsive overview provides:

- High, Moderate, Stable, and Insufficient-data counts
- a scoped Student list for Professor and staff roles
- the selected Student's risk level and reasons
- academic trend
- Subjects analyzed
- data completeness
- published Subject-level checkpoints and signals
- an expandable methodology explanation

The page uses shared semantic surface, text, border, success, warning, and danger tokens, so the same screen supports light and dark modes. On narrow screens the filters, summary, Student list, and details collapse to single-column layouts.

## Phase 2 intervention workflow

### Suggested Study Plans

Study Plans are generated on demand from the same scoped Phase 1 assessment. They are not stored as an academic decision. Every plan is labeled **Suggested Academic Support Plan** and varies deterministically by the current signal:

- High: five to seven larger recovery sessions focused first on High, Moderate, and declining Subjects
- Moderate: four to six targeted review and preparation sessions
- Stable: three maintenance sessions
- Insufficient data: two neutral preliminary sessions without invented focus Subjects

Each plan includes its objective, published-data completeness, focus Subject evidence, and a weekly session schedule. It does not calculate GWA, declare Pass or Fail, or alter any official result.

### Adviser Alerts

Adviser Alerts are computed each time from current Published snapshots. They are shown for High and Moderate risk and for a declining overall trend. Stable Students without a declining trend are excluded. Alerts are not persisted as a second source of truth.

Professors receive alerts only for Students reachable through their current Professor, Section, Subject, finalized Enrollment, and Published Grade Sheet scope. Registrar Staff and Admin receive the institution-wide view allowed by their existing platform policy.

### Academic support notices

Professor, Registrar Staff, and Admin users may send a neutral academic support notice from an Adviser Alert. A Professor's scope is revalidated on the server at send time. The notice stores only the displayed risk level and safe explanatory context; it does not copy private grade snapshots, credentials, or unrelated personal data.

The send route is limited to 10 requests per minute. An exact repeat from the same sender to the same Student with the same current risk level, title, and message within 15 minutes returns the existing notice. Creation of a new notice and its audit event occur in one transaction.

Students see only their own notices in **My Notices**. The unread count comes from the database. Loading the list does not mark notices as read; opening a notice records `read_at`. Repeated opens are idempotent and create only one read audit event.

Intervention events are append-only and use these actions:

- `monitoring.risk_notice.sent`
- `monitoring.risk_notice.read`

Both events retain the actor, actor role, target Student, risk level, safe context, and timestamp. A deleted notice or Student may be set to null on the audit relationship so the historical event remains available.

Phase 2 endpoints are:

- **GET /api/monitoring/study-plans**
- **GET /api/monitoring/students/{student}/study-plan**
- **GET /api/monitoring/adviser-alerts**
- **POST /api/monitoring/students/{student}/risk-notifications**
- **GET /api/monitoring/my-risk-notifications**
- **PATCH /api/monitoring/risk-notifications/{notification}/read**

The unified Monitoring page exposes role-safe tabs. Students see Early Warnings, Study Plan, My Notices, and AI Help. Professors, Registrar Staff, and Admin see Early Warnings, Study Plans, Adviser Alerts, and AI Help. All panels use semantic theme tokens and collapse to mobile layouts.

## Phase 3 AI Help

AI Help is an advisory coach layered on the existing Phase 1 assessment. It receives a minimized summary of the selected Student's published immutable Grade Sheet snapshots: the current risk signal, reasons, trend, completeness, and Subject-level official evidence. It does not query live grade workspaces, recalculate grades, calculate GWA, decide Pass or Fail, invent institutional policy, diagnose a Student, predict outcomes, or write academic records.

The request is stateless. Conversation display exists only in the current browser page and no prompt or reply is stored. Students may request help only for their own Student identity. Professors remain limited to Students in their current Section and Subject assignments. Registrar Staff and Admin retain their Phase 1 institution scope. Monitoring additionally constrains Professor use to Web, Registrar Staff to Desktop, Students to Web or Mobile, and Admin to Web or Desktop.

The endpoint accepts a plain-text question of 2 to 1,500 characters and rejects markup. Prompt boundaries identify the question as untrusted input, system rules prohibit disclosure and unauthorized academic claims, and provider output is stripped of HTML. Responses are labeled **AI-generated academic guidance** and include an advisory disclaimer. Language style is inferred from each current question: English remains the default, while Tagalog and Taglish questions receive matching guidance. The service does not force one language for all users.

When the provider is disabled, incomplete, unavailable, times out, returns malformed content, or returns an unauthorized academic claim, the endpoint returns deterministic language-matched guidance from the same published risk context. Users therefore retain safe support without a provider dependency.

Provider configuration is isolated under these environment variables:

- `MONITORING_AI_ENABLED`
- `MONITORING_AI_PROVIDER` (`openai`, `gemini`, or `ollama`)
- `MONITORING_AI_MODEL`
- `MONITORING_AI_BASE_URL`
- `MONITORING_AI_API_KEY` (not required by local Ollama)
- `MONITORING_AI_TIMEOUT`

The default is disabled. Secrets stay in environment configuration and are never returned, logged, audited, or committed. Tests replace the provider abstraction or fake HTTP, so the suite requires no network or real API key.

For troubleshooting, first call **GET /api/monitoring/ai-status** and confirm `configured`. A false value means the deterministic fallback is intentionally active; check the enable flag, supported provider name, model, base URL, server-side key, and timeout. Provider failures and timeouts also switch to fallback and log only a provider identifier and exception class. No provider body, prompt, response, Student evidence, or credential is logged. Rate-limit responses use the normal Laravel 429 response, and access failures retain the Monitoring role and platform response.

Phase 3 endpoints are:

- **GET /api/monitoring/ai-status**
- **POST /api/monitoring/students/{student}/ai-help** (10 requests per minute)

Each completed request appends `monitoring.ai_help.requested` to the existing immutable Monitoring intervention audit. Safe metadata includes actor, role, target Student, risk signal, provider name, provider/fallback source, broad fallback reason, detected language, term identifiers, completeness state, published Subject count, and timestamp. It excludes the question, response, grade values, personal profile fields, credentials, and provider error details.

## Reference branch reconciliation

The reference branch supplied the original Early Warnings, Study Plans, Adviser Alerts, AI Help, and notification presentation. The current implementation retains the useful role-aware ideas while replacing the legacy **grades** / **grading_periods** read path, hard-coded 75 / 82 thresholds, local-only read state, and persisted alert duplication.

Phase 3 keeps the official snapshot contract and does not infer or store institutional rules that have not been configured. Phase 3 completes Academic Monitoring; no Phase 4 is defined.
