<?php

declare(strict_types=1);

namespace rapttor\yii2legal\stores;

use rapttor\yii2legal\Legal;
use Yii;
use yii\db\IntegrityException;
use yii\db\Query;

/**
 * Default store: one row per account in the shared metadata table (`Legal::$metadataTable`, default `rapttor_metadata`):
 * `key` = `legal`, `remote_table` = `profile`, `remote_id` = the user id. `content` holds the current state (JSON), `history`
 * the earlier versions of changed answers (JSON list, newest last). Other features keep their own rows under other keys.
 */
final class MetadataConsentStore implements ConsentStore
{
    private const HISTORY_MAX = 200;

    public function __construct(private Legal $legal)
    {
    }

    public function load(string $userId): array
    {
        $row = $this->row($userId);
        $state = $row === null ? null : json_decode((string)$row['content'], true);
        return is_array($state) ? $state : [];
    }

    public function save(string $userId, array $state): void
    {
        $db = Yii::$app->get($this->legal->db);
        $t = $this->legal->metadataTable;
        for ($try = 0; $try < 4; $try++) {
            $row = $this->row($userId);
            $now = time();
            if ($row === null) {
                try {
                    $db->createCommand()->insert($t, $this->where($userId) + [
                        'content' => $this->json($state), 'history' => $this->json([]), 'rev' => 1, 'updated_at' => $now,
                    ])->execute();
                    return;
                } catch (IntegrityException) { // another request created it first: merge into theirs
                    continue;
                }
            }
            $old = json_decode((string)$row['content'], true);
            $history = json_decode((string)$row['history'], true);
            $history = is_array($history) ? $history : [];
            foreach (is_array($old) ? $old : [] as $key => $entry) {
                if (isset($state[$key]) && $state[$key] != $entry) { // answer or version changed: keep what it was
                    $history[] = ['key' => $key, 'replaced_at' => $now] + $entry;
                }
            }
            $n = $db->createCommand()->update($t, [
                'content' => $this->json($state), 'history' => $this->json(array_slice($history, -self::HISTORY_MAX)), 'rev' => (int)$row['rev'] + 1, 'updated_at' => $now,
            ], ['id' => $row['id'], 'rev' => $row['rev']])->execute();
            if ($n === 1) {
                return;
            }
        }
        throw new \RuntimeException('Could not save the consents: the record keeps changing.');
    }

    /** Earlier versions of a user's answers, oldest first. @return list<array<string,mixed>> */
    public function history(string $userId): array
    {
        $row = $this->row($userId);
        $h = $row === null ? null : json_decode((string)$row['history'], true);
        return is_array($h) ? $h : [];
    }

    /** @return array<string,mixed>|null */
    private function row(string $userId): ?array
    {
        $r = (new Query())->from($this->legal->metadataTable)->where($this->where($userId))->one(Yii::$app->get($this->legal->db));
        return $r === false ? null : $r;
    }

    /** @return array<string,string> */
    private function where(string $userId): array
    {
        return ['key' => $this->legal->metadataKey, 'remote_table' => $this->legal->metadataRemoteTable, 'remote_id' => $userId];
    }

    private function json(array $v): string
    {
        return json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
