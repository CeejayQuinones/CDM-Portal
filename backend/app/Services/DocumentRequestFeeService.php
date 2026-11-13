<?php

namespace App\Services;

use App\Models\DocumentRequest;
use Carbon\CarbonImmutable;

class DocumentRequestFeeService
{
    public function requestDate(): string
    {
        return CarbonImmutable::now('Asia/Manila')->toDateString();
    }

    public function hasRequestedToday(int $studentId, int $documentTypeId, string $date): bool
    {
        return DocumentRequest::query()
            ->where('student_id', $studentId)
            ->where('document_type_id', $documentTypeId)
            ->whereDate('request_date', $date)
            ->exists();
    }
}
