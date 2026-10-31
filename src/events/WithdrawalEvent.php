<?php

declare(strict_types=1);

namespace rapttor\yii2legal\events;

use yii\base\Event;

/** Raised when a consumer confirmed a withdrawal. Process the refund / end the contract in your handler. */
final class WithdrawalEvent extends Event
{
    public string $contract = '';
    public string $email = '';
    public ?string $customerName = null;
    public int $requestedAt = 0;
    public int $id = 0;
}
