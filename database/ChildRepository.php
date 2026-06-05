<?php

declare(strict_types=1);

namespace GuardKids\Database;

final class ChildRepository extends Repository
{
    protected function tableSuffix(): string
    {
        return 'children';
    }
}
