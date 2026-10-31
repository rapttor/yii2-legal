<?php

declare(strict_types=1);

namespace rapttor\yii2legal\stores;

use rapttor\yii2legal\Legal;
use rapttor\yii2metadata\Metadata;
use Yii;

/**
 * Default store: one row per account in the shared metadata table, through `rapttor/yii2-metadata`: `key` = `legal`,
 * `remote_table` = `profile`, `remote_id` = the user id. `content` is the current state of every consent, `history` the earlier
 * values of consents that changed. Uses the application's `metadata` component when it is registered (so your `noHistory`,
 * `historyMax` and table settings apply), otherwise a default one on `Legal::$metadataTable`.
 */
final class MetadataConsentStore implements ConsentStore
{
    private ?Metadata $metadata = null;

    public function __construct(private Legal $legal)
    {
    }

    public function load(string $userId): array
    {
        return $this->metadata()->get($this->legal->metadataKey, $this->legal->metadataRemoteTable, $userId);
    }

    public function save(string $userId, array $state): void
    {
        $this->metadata()->set($this->legal->metadataKey, $this->legal->metadataRemoteTable, $userId, $state);
    }

    /** Earlier values of a user's consents, oldest first (entry, rev, replaced_at, value). @return list<array<string,mixed>> */
    public function history(string $userId, ?string $consent = null): array
    {
        return $this->metadata()->history($this->legal->metadataKey, $this->legal->metadataRemoteTable, $userId, $consent);
    }

    private function metadata(): Metadata
    {
        return $this->metadata ??= Yii::$app->has('metadata')
            ? Yii::$app->get('metadata')
            : new Metadata(['db' => $this->legal->db, 'table' => $this->legal->metadataTable]);
    }
}
