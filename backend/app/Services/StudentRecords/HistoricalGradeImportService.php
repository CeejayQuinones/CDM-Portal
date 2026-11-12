<?php

namespace App\Services\StudentRecords;

use App\Models\HistoricalGradeImportPreview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HistoricalGradeImportService
{
    public function __construct(private readonly HistoricalGradeService $grades) {}

    public function preview(array $rows, User $actor): array
    {
        // Preview data contains academic identifiers; retain it only briefly.
        HistoricalGradeImportPreview::where('expires_at', '<', now()->subDay())
            ->orWhere('consumed_at', '<', now()->subDay())->delete();
        $seen = [];
        $display = [];
        $counts = ['total' => count($rows), 'valid' => 0, 'invalid' => 0, 'duplicates' => 0, 'unknown_students' => 0, 'unknown_subjects' => 0, 'manual_review' => 0];
        foreach ($rows as $row) {
            $input = $row['data'];
            $identity = implode('|', array_map(fn ($value) => mb_strtolower(trim((string) $value)), [
                $input['student_number'], $input['academic_year'], $input['semester'], $input['subject_code'],
            ]));
            try {
                $resolved = $this->grades->resolve($input);
                if (isset($seen[$identity])) {
                    $status = 'duplicate';
                    $errors = ['Duplicate of spreadsheet row '.$seen[$identity].'.'];
                } else {
                    $seen[$identity] = $row['row'];
                    $status = 'valid';
                    $errors = [];
                }
            } catch (ValidationException $exception) {
                $errors = collect($exception->errors())->flatten()->all();
                $status = str_contains(implode(' ', $errors), 'already exists') ? 'duplicate' : 'invalid';
                if (isset($exception->errors()['student_number'])) {
                    $counts['unknown_students']++;
                }
                if (isset($exception->errors()['subject_code'])) {
                    $counts['unknown_subjects']++;
                }
                $counts['manual_review']++;
            }
            $counts[$status === 'duplicate' ? 'duplicates' : $status]++;
            $display[] = ['row' => $row['row'], 'data' => $input, 'status' => $status, 'errors' => $errors];
        }
        $token = null;
        if ($counts['valid'] === $counts['total']) {
            $token = Str::random(64);
            HistoricalGradeImportPreview::create([
                'token_hash' => hash('sha256', $token), 'actor_user_id' => $actor->id,
                'rows' => $rows, 'expires_at' => now()->addMinutes(15),
            ]);
        }

        return ['counts' => $counts, 'rows' => $display, 'preview_token' => $token,
            'message' => $token ? 'Review every row, then confirm the entire import.' : 'Correct the spreadsheet and preview it again; no records were saved.'];
    }

    public function confirm(string $token, User $actor): array
    {
        return DB::transaction(function () use ($token, $actor): array {
            $preview = HistoricalGradeImportPreview::where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            abort_unless($preview && $preview->actor_user_id === $actor->id && ! $preview->consumed_at && $preview->expires_at->isFuture(), 409, 'This import preview expired or was already confirmed. Preview the file again.');
            // Recheck all relationships inside the transaction before making any write.
            foreach ($preview->rows as $row) {
                $this->grades->resolve($row['data']);
            }
            foreach ($preview->rows as $row) {
                $this->grades->create($row['data'], $actor, 'import');
            }
            $preview->forceFill(['consumed_at' => now()])->save();

            return ['imported' => count($preview->rows), 'message' => 'Historical grades imported and verified.'];
        }, 3);
    }
}
