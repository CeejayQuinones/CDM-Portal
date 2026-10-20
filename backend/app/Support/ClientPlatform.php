<?php

namespace App\Support;

final class ClientPlatform
{
    public const HEADER = 'X-CDM-Client';

    public const WEB = 'web';

    public const DESKTOP = 'desktop';

    public const MOBILE = 'mobile';

    public const ALL = [
        self::WEB,
        self::DESKTOP,
        self::MOBILE,
    ];

    public static function ability(string $client): string
    {
        return 'client:'.$client;
    }
}
