<?php

namespace App\Policies;

use App\Models\DocumentRequest;
use App\Models\User;

class DocumentRequestPolicy
{
    public function view(User $user, DocumentRequest $documentRequest): bool
    {
        return $user->student?->id === $documentRequest->student_id;
    }
}
