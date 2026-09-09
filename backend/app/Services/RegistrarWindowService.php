<?php

namespace App\Services;

class RegistrarWindowService
{
    private const WINDOW_4 = [
        'certified true copy', 'certification authentication and verification', 'cav',
        'course description', 'general weighted average', 'gwa', 'nstp serial number',
        'medium of instruction', 'transfer credentials', 'honorable dismissal',
        'transcript of records', 'tor', 'units earned',
    ];

    private const WINDOW_3 = [
        'adding changing dropping form', 'correction of name', 'inc completion form',
        'leave of absence', 'loa', 'petition of subject', 'returnee form',
        'shifting of course program', 'scholarship', 'withdrawal of subjects',
    ];

    public function forDocument(string $name): string
    {
        $normalized = trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($name)) ?? '');

        if (in_array($normalized, self::WINDOW_4, true)) {
            return 'Window 4';
        }
        if (in_array($normalized, self::WINDOW_3, true)) {
            return 'Window 3';
        }

        return 'Window 1 or 2';
    }
}
