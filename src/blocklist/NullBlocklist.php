<?php

declare(strict_types=1);

namespace rapttor\yii2legal\blocklist;

/** Default: only the app's own suppression table is used. */
final class NullBlocklist implements BlocklistInterface
{
    public function add(string $email, array $context = []): void
    {
    }
}
