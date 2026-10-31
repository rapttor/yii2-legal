<?php

declare(strict_types=1);

namespace rapttor\yii2legal\stores;

use rapttor\yii2legal\Legal;
use Yii;
use yii\db\Query;

/** Default store: one row per account and consent key in `Legal::$consentTable`. */
final class TableConsentStore implements ConsentStore
{
    public function __construct(private Legal $legal)
    {
    }

    public function load(string $userId): array
    {
        $rows = (new Query())->from($this->legal->consentTable)->where(['user_id' => $userId])->all(Yii::$app->get($this->legal->db));
        $out = [];
        foreach ($rows as $r) {
            $extra = json_decode((string)($r['evidence'] ?? ''), true);
            $out[$r['ckey']] = ['v' => $r['version'], 'granted' => (bool)$r['granted'], 'at' => (int)$r['at'], 'lang' => $r['lang'], 'source' => $r['source']]
                + (is_array($extra) ? $extra : []);
        }
        return $out;
    }

    public function save(string $userId, array $state): void
    {
        $db = Yii::$app->get($this->legal->db);
        foreach ($state as $key => $s) {
            $extra = array_diff_key($s, array_flip(['v', 'granted', 'at', 'lang', 'source'])); // ev (hashes) and history
            $row = ['version' => $s['v'], 'granted' => $s['granted'] ? 1 : 0, 'at' => $s['at'], 'lang' => $s['lang'] ?? null, 'source' => $s['source'] ?? null,
                'evidence' => $extra === [] ? null : json_encode($extra, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];
            $exists = (new Query())->from($this->legal->consentTable)->where(['user_id' => $userId, 'ckey' => $key])->exists($db);
            $exists
                ? $db->createCommand()->update($this->legal->consentTable, $row, ['user_id' => $userId, 'ckey' => $key])->execute()
                : $db->createCommand()->insert($this->legal->consentTable, $row + ['user_id' => $userId, 'ckey' => $key])->execute();
        }
    }
}
