<?php

declare(strict_types=1);

namespace rapttor\yii2legal\stores;

/** Where an account's consents are kept. State per key: ['v' => version, 'granted' => bool, 'at' => unix time, 'lang' => tag, 'source' => string]. */
interface ConsentStore
{
    /** @return array<string,array<string,mixed>> */
    public function load(string $userId): array;

    /** @param array<string,array<string,mixed>> $state the complete state to keep */
    public function save(string $userId, array $state): void;
}
