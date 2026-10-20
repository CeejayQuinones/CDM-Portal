# Academic and Enrollment Phase 2

## Academic terms

Registrar Staff and Admin manage academic years and Enrollment periods from **Enrollment → Enrollment Periods**. Only one academic year may be active. A referenced year keeps its label and dates, and an active year cannot be completed while it has a future enabled period.

Periods remain unique per academic year and semester. Enabled windows cannot overlap, and only the active academic year may have an enabled period. Upcoming years can be prepared with disabled periods. Student and admitted-Guest eligibility continues to be enforced by the backend against the period's exact academic-year and semester foreign keys.

## Enrollment documents

Each period configures documents independently for Regular, Irregular, Transferee, and Returnee applicants. A document is either required, optional, or not configured for that classification. Required documents block submission; optional documents remain visible and uploadable but do not block submission. Existing legacy JSON arrays are interpreted as required documents, and verified Student Documents are reused without granting Students management access to official records.

Document configuration freezes after the first application is created for the period so historical applications keep their original requirements.

## Curriculum, Sections, and Scheduling

Sections now link to one curriculum through `sections.curriculum_id`. The migration fills this link only where the Section's Course has exactly one active curriculum. It leaves ambiguous rows unassigned for staff review.

Scheduling retrieves only active subjects belonging to the Section curriculum, year level, and semester. Professor assignment remains Event-independent and uses the existing active-Professor and conflict checks. A Section without an unambiguous active curriculum returns a configuration message instead of a misleading empty subject picker.

## Grading scale policy

No grading-scale seeder was added. The repository documentation states that no approved institutional grade-point mapping is available, while the local database contains manually configured ranges without a repository source of authority. Encoding those values in a production seeder would invent institutional policy. Obtain an approved scale before adding an idempotent, insert-only seeder; it must leave any existing rows unchanged.
