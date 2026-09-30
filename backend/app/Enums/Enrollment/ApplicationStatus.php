<?php

namespace App\Enums\Enrollment;

// Application-layer contract only; does not replace existing enrollments.status.
enum ApplicationStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Enrolled = 'enrolled';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
