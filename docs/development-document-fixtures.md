# Development document fixtures

The document images used for local AI workflow demos live in:

```text
backend/storage/app/test-documents/
```

This directory is ignored by Git because the available source images contain visible personal information. They must never be committed, served publicly, or treated as authentic student records. Production upload and storage code does not reference this directory.

Local filenames and their actual document classifications:

| Sample key | Local filename | Actual document type | Current AI support |
| --- | --- | --- | --- |
| `psa` | `psa-sample.jpg` | Birth Certificate / Certificate of Live Birth | Supported |
| `certificate-of-enrollment` | `certificate-of-enrollment-sample.jpg` | Enrollment slip | Supported as Certificate of Enrollment |
| `registration-form` | `registration-form-sample.jpg` | Registration Form | Not supported |
| `school-certificate` | `school-certificate-sample.jpg` | Bona-fide enrollment certification / Certificate of Enrollment | Supported as Certificate of Enrollment; not a Good Moral sample |
| `form-137` | `form-137-sample.webp` | Form 137 / learner permanent record | Supported for the local helper |
| `good-moral` | `good-moral-sample.png` | Certificate of Good Moral Character | Supported |

Install the mapped sample for a local student:

```bash
php artisan demo:student-documents 123 psa
```

Explicitly associate a wrong document with the Birth Certificate slot to test mismatch handling:

```bash
php artisan demo:student-documents 123 registration-form --document-type="Birth Certificate" --replace
```

The command copies the source to Laravel's existing private `local` disk, updates `StudentDocument.file_path`, and lets `StudentDocumentObserver` create and queue the analysis normally. Existing stored files require `--replace`.

The command is registered only when `APP_ENV=local`.
