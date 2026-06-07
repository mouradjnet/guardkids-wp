<?php

declare(strict_types=1);

namespace GuardKids\Database;

final class SafeZoneRepository extends Repository
{
    protected function tableSuffix(): string
    {
        return 'safe_zones';
    }
}
