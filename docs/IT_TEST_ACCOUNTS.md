# CDM Portal IT Test Accounts

This document records the roles and account purposes required for local IT validation. Operational-account credentials belong in the validation team's approved secret channel or password manager; no passwords belong in this document.

The local validation database was checked on 2026-10-03 and contains one active account for each required role: Registrar Staff, Admin, Student, Professor, and Guest. It also contains the required Student academic identity for the Student account. Password validity must be confirmed privately during the login test.

## Registrar Staff

- Email/username: __________
- Role: Registrar Staff
- Platform: Electron desktop only
- Purpose: Dashboard, Admission, Enrollment, Student Management, Document Requests, Appointments, Physical Records, Monitoring, and settings
- Credential owner/contact: __________
- Reset completed/date: __________

## Admin

- Email/username: __________
- Role: Admin/System Admin
- Platform: Web and Electron desktop
- Purpose: Admission, authorized Enrollment/system management, settings, and cross-module platform verification
- Credential owner/contact: __________
- Reset completed/date: __________

## Student

- Email/username: __________
- Role: Student
- Platform: Web and Capacitor mobile
- Purpose: Dashboard, own Enrollment, Document Requests, Academic Monitoring notices, settings, and profile photo
- Student number: __________
- Current course/year/Section: __________
- Credential owner/contact: __________
- Reset completed/date: __________

## Professor

- Email/username: __________
- Role: Professor
- Platform: Web and Mobile
- Purpose: Dashboard, schedule, assigned class/roster surfaces, grading and bulk assessment entry, and negative staff-route tests
- Assigned Section/subject: __________
- Credential owner/contact: __________
- Reset completed/date: __________

## Guest/applicant

- Email/username: __________
- Role: Guest
- Platform: Web only
- Purpose: Registration/login, own Admission application/exam/result history, and negative staff/academic-route tests
- Admission cycle/application: __________
- Credential owner/contact: __________
- Reset completed/date: __________

## Account preparation rules

1. Confirm the account is active and has exactly the expected role before distributing credentials.
2. Confirm Registrar desktop-only, Admin web/desktop, Student web/mobile, Professor web/mobile, and Guest web-only behavior before workflow testing.
3. Use separate eligible and ineligible Students only when the local validation database intentionally provisions them. Do not alter a legitimate Student merely to force a negative case.
4. Restore any password or status changed for negative testing immediately after recording the result.
5. Never share bearer tokens, password hashes, verification codes, or database connection values.

## Local generation and reset references

The repository contains local seed definitions under `backend/database/seeders`. Do not copy operational credentials into this document or an IT report.

- On a new, dedicated, empty local database, `php artisan db:seed` creates the baseline roles, accounts, profiles, personnel, academic reference data, document types, and physical-record seed data. The baseline seeders are idempotent, but they remain local/testing fixtures and refuse direct production execution.
- `php artisan db:seed --class=AdminUserSeeder` is a local/testing-only Admin create/reset seeder. It refuses production and requires `DEVELOPMENT_ADMIN_PASSWORD`; no password is defined in source. Use it only with validation-owner approval, transmit the credential privately, and change it after validation.
- `php artisan portal:dev-seed` is the explicit local/testing Admission and Enrollment scenario generator. It is not needed when the five prepared accounts and required workflows already exist. If the validation lead authorizes it, remove only its tracked data afterward with `php artisan portal:dev-seed --cleanup`.
- Guest test accounts can be created through the normal public registration and verification flow using a validation-owned mailbox.
- Student, Professor, and Registrar password changes should use their normal authenticated password-change flow. If the current password is unavailable, use a controlled local-only operator reset procedure approved by the validation owner; do not add a default password to documentation or source.

## Pre-validation account check

| Check | Result | Notes |
| --- | --- | --- |
| Registrar account active and desktop login verified | ☐ Pass ☐ Fail | __________ |
| Admin account active and web/desktop login verified | ☐ Pass ☐ Fail | __________ |
| Student account active, academically linked, and web/mobile login verified | ☐ Pass ☐ Fail | __________ |
| Professor account active and web/mobile login verified | ☐ Pass ☐ Fail | __________ |
| Guest account active and web login/Admission access verified | ☐ Pass ☐ Fail | __________ |
| Passwords stored outside Git and shared only with validators | ☐ Pass ☐ Fail | __________ |
| Negative-test status/password changes restored | ☐ Pass ☐ Fail | __________ |
