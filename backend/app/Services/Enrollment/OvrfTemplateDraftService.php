<?php

namespace App\Services\Enrollment;

use App\Models\OvrfTemplateDraft;
use App\Models\OvrfTemplateDraftEvent;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class OvrfTemplateDraftService
{
    private const DISK = 'local';

    public function upload(User $actor, UploadedFile $file): OvrfTemplateDraft
    {
        $bytes = $file->getContent();
        $pageCount = $this->validatePdf($bytes);
        $path = 'ovrf-template-drafts/'.Str::uuid().'.pdf';

        if (! Storage::disk(self::DISK)->put($path, $bytes)) {
            throw new RuntimeException('The OVRF draft could not be stored privately.');
        }

        try {
            return DB::transaction(function () use ($actor, $file, $bytes, $pageCount, $path): OvrfTemplateDraft {
                $draft = OvrfTemplateDraft::create([
                    'original_filename' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255),
                    'private_path' => $path,
                    'sha256' => hash('sha256', $bytes),
                    'size_bytes' => strlen($bytes),
                    'page_count' => $pageCount,
                    'status' => 'draft',
                    'uploaded_by_user_id' => $actor->id,
                ]);
                $this->event($draft, $actor, 'uploaded');

                return $draft;
            });
        } catch (\Throwable $exception) {
            Storage::disk(self::DISK)->delete($path);

            throw $exception;
        }
    }

    public function archive(User $actor, OvrfTemplateDraft $draft): OvrfTemplateDraft
    {
        return DB::transaction(function () use ($actor, $draft): OvrfTemplateDraft {
            $locked = OvrfTemplateDraft::query()->lockForUpdate()->findOrFail($draft->id);
            abort_unless($locked->status === 'draft', 409, 'Only an active draft can be archived.');
            $locked->update(['status' => 'archived', 'archived_at' => now(), 'archived_by_user_id' => $actor->id]);
            $this->event($locked, $actor, 'archived');

            return $locked;
        });
    }

    public function preview(User $actor, OvrfTemplateDraft $draft): string
    {
        abort_unless(preg_match('/\Aovrf-template-drafts\/[0-9a-f-]{36}\.pdf\z/i', $draft->private_path) === 1, 404);
        $bytes = Storage::disk(self::DISK)->get($draft->private_path);
        abort_unless(is_string($bytes) && hash_equals($draft->sha256, hash('sha256', $bytes)), 404, 'The stored OVRF draft is unavailable or has changed.');
        $this->event($draft, $actor, 'previewed');

        return $bytes;
    }

    private function event(OvrfTemplateDraft $draft, User $actor, string $action): void
    {
        OvrfTemplateDraftEvent::create([
            'ovrf_template_draft_id' => $draft->id,
            'actor_user_id' => $actor->id,
            'action' => $action,
            'created_at' => now(),
        ]);
    }

    private function validatePdf(string $bytes): int
    {
        // Deliberately accept only simple, classic-xref PDFs until an approved PDF parser is available.
        if (strlen($bytes) < 100 || ! preg_match('/\A%PDF-1\.[0-7][\r\n]/', $bytes)) {
            throw ValidationException::withMessages(['file' => 'The file must be a supported PDF version (1.0–1.7) with a valid PDF header.']);
        }
        if (preg_match('/\/(?:Encrypt)\b/i', $bytes)) {
            throw ValidationException::withMessages(['file' => 'Encrypted PDFs are not supported for OVRF drafts. Upload an unencrypted blank PDF.']);
        }
        if (preg_match('/\/(?:JavaScript|JS|OpenAction|AA|Launch|URI|RichMedia|XFA)\b/i', $bytes)) {
            throw ValidationException::withMessages(['file' => 'PDFs with active content or external links are not supported for OVRF drafts.']);
        }
        if (preg_match('/\/(?:EmbeddedFile|Filespec)\b/i', $bytes)) {
            $message = preg_match('/\/AFRelationship\s*\/C2PA_Manifest\b/', $bytes)
                && preg_match('/\/Subtype\s*\/application#2Fc2pa\b/i', $bytes)
                ? 'This PDF contains an embedded Content Credentials (C2PA) manifest. Embedded files are not supported for OVRF drafts; export a copy without Content Credentials.'
                : 'This PDF contains an embedded file, which is not supported for OVRF drafts. Export a PDF without attachments.';
            throw ValidationException::withMessages(['file' => $message]);
        }
        if (preg_match('/\/(?:ObjStm|XRef)\b|\/#/i', $bytes)) {
            throw ValidationException::withMessages(['file' => 'This PDF uses compressed object streams, cross-reference streams, or escaped names, which the draft validator does not support. Export a standard PDF with a classic cross-reference table.']);
        }
        if (preg_match('/startxref\s+(\d+)\s+%%EOF\s*\z/s', $bytes, $match) !== 1
            || substr($bytes, (int) ($match[1] ?? -1), 4) !== 'xref'
            || ! preg_match('/\/Root\s+\d+\s+\d+\s+R\b/', $bytes)) {
            throw ValidationException::withMessages(['file' => 'The PDF cross-reference table or trailer is missing or unsupported. Export a standard PDF with a classic cross-reference table.']);
        }

        preg_match_all('/\/Type\s*\/Page\b/', $bytes, $pages);
        $count = count($pages[0]);
        if ($count < 1 || $count > 10) {
            throw ValidationException::withMessages(['file' => 'The PDF must contain between 1 and 10 readable pages.']);
        }

        return $count;
    }
}
