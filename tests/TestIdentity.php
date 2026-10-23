<?php

declare(strict_types=1);

namespace rapttor\yii2legal\tests;

use yii\base\BaseObject;
use yii\web\IdentityInterface;

final class TestIdentity extends BaseObject implements IdentityInterface
{
    public int|string $id = 7;

    public static function findIdentity($id): ?static
    {
        return new static(['id' => $id]);
    }

    public static function findIdentityByAccessToken($token, $type = null): ?static
    {
        return null;
    }

    public function getId(): int|string
    {
        return $this->id;
    }

    public function getAuthKey(): string
    {
        return 'k';
    }

    public function validateAuthKey($authKey): bool
    {
        return true;
    }
}
