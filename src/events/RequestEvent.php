<?php

declare(strict_types=1);

namespace rapttor\yii2legal\events;

use yii\base\Event;

/** Raised after a privacy or legal request was stored (see Requests). */
final class RequestEvent extends Event
{
    public int $id = 0;
    public string $type = 'other';
    public string $email = '';
    public ?string $requesterName = null;
    public string $message = '';
    public ?int $userId = null;
    public int $createdAt = 0;
}
