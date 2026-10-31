<?php

declare(strict_types=1);

namespace rapttor\yii2legal\stores;

use yii\base\BaseObject;
use yii\db\ActiveRecord;

/**
 * Keeps the state as JSON in one attribute of your user model (a TEXT or JSON column), for example `consents`:
 *
 *     'consentStore' => ['class' => JsonAttributeConsentStore::class, 'modelClass' => app\models\User::class, 'attribute' => 'consents'],
 */
final class JsonAttributeConsentStore extends BaseObject implements ConsentStore
{
    /** @var class-string<ActiveRecord> */
    public string $modelClass;
    public string $attribute = 'consents';

    public function load(string $userId): array
    {
        $m = ($this->modelClass)::findOne($userId);
        $d = $m === null ? null : json_decode((string)$m->{$this->attribute}, true);
        return is_array($d) ? $d : [];
    }

    public function save(string $userId, array $state): void
    {
        $m = ($this->modelClass)::findOne($userId);
        if ($m !== null) {
            $m->updateAttributes([$this->attribute => json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
        }
    }
}
