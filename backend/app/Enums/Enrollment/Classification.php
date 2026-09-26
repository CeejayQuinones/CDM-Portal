<?php

namespace App\Enums\Enrollment;

// Future Enrollment application data; never an auth role or automatic Student status.
enum Classification: string
{
    case Regular = 'regular';
    case Irregular = 'irregular';
    case Transferee = 'transferee';
    case Returnee = 'returnee';
}
