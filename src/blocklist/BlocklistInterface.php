<?php

declare(strict_types=1);

namespace rapttor\yii2legal\blocklist;

/** A list of addresses that must never be mailed again, kept outside the app (for example at the email provider). */
interface BlocklistInterface
{
    /** @param array<string,mixed> $context e.g. ['source' => 'legal', 'reason' => 'user request'] @throws \Throwable when the provider refuses */
    public function add(string $email, array $context = []): void;
}
