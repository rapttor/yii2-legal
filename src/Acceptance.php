<?php

declare(strict_types=1);

namespace rapttor\yii2legal;

use Yii;
use yii\db\Connection;
use yii\db\Query;

/**
 * Record of who accepted which version of which document, and when: the evidence for "the user agreed to the Terms".
 * Re-acceptance is needed when a document's MAJOR or MINOR version moved on; PATCH changes (wording fixes) do not.
 * IP addresses are stored only as a keyed hash.
 */
final class Acceptance
{
    public function __construct(private Legal $legal)
    {
    }

    public function db(): Connection
    {
        /** @var Connection */
        return Yii::$app->get($this->legal->db);
    }

    /** Create the table (also used by the migration). */
    public static function createTable(Connection $db, string $table): void
    {
        if ($db->getTableSchema($table, true) !== null) {
            return;
        }
        $db->createCommand()->createTable($table, [
            'id' => 'pk',
            'user_id' => 'integer NULL',
            'subject' => 'string(64) NULL',
            'document' => 'string(48) NOT NULL',
            'version' => 'string(16) NOT NULL',
            'lang' => 'string(12) NOT NULL',
            'release' => 'string(16) NOT NULL',
            'source' => 'string(24) NOT NULL',
            'ip_hash' => 'string(64) NULL',
            'accepted_at' => 'integer NOT NULL',
        ])->execute();
        $db->createCommand()->createIndex('idx_legal_acceptance_user', $table, ['user_id', 'document'])->execute();
    }

    /**
     * Record acceptance of the current version of documents.
     *
     * @param int|null $userId logged-in user, or null for a guest
     * @param list<string>|null $docIds default: the site's `acceptDocs`
     * @param string|null $subject for guests: an email or other stable identifier (stored as given: keep it minimal)
     * @return int rows written
     */
    public function record(?int $userId, ?array $docIds = null, string $source = 'signup', ?string $subject = null, ?string $lang = null): int
    {
        $docIds ??= $this->legal->acceptDocs;
        $lang ??= $this->legal->currentLanguage();
        $n = 0;
        foreach ($docIds as $id) {
            $d = $this->legal->document($id, $lang);
            if ($d === null) {
                continue;
            }
            $this->db()->createCommand()->insert($this->legal->acceptanceTable, [
                'user_id' => $userId, 'subject' => $subject, 'document' => $id, 'version' => $d['version'], 'lang' => $d['lang'],
                'release' => $this->legal->release(), 'source' => $source, 'ip_hash' => $this->ipHash(), 'accepted_at' => time(),
            ])->execute();
            $n++;
        }
        return $n;
    }

    /** Highest accepted version per document for a user. @return array<string,string> document => version */
    public function accepted(int $userId): array
    {
        $rows = (new Query())->select(['document', 'version'])->from($this->legal->acceptanceTable)->where(['user_id' => $userId])->all($this->db());
        $out = [];
        foreach ($rows as $r) {
            if (!isset($out[$r['document']]) || version_compare($r['version'], $out[$r['document']], '>')) {
                $out[$r['document']] = $r['version'];
            }
        }
        return $out;
    }

    /** Documents the user still has to accept (never accepted, or a newer MAJOR/MINOR is in force). @return list<array<string,mixed>> manifest entries */
    public function pending(int $userId, ?array $docIds = null): array
    {
        $accepted = $this->accepted($userId);
        $out = [];
        foreach ($docIds ?? $this->legal->acceptDocs as $id) {
            $d = $this->legal->document($id);
            if ($d !== null && (!isset($accepted[$id]) || self::newer($d['version'], $accepted[$id]))) {
                $out[] = $d;
            }
        }
        return $out;
    }

    public function hasAcceptedAll(int $userId): bool
    {
        return $this->pending($userId) === [];
    }

    /** True when $current differs from $accepted in MAJOR or MINOR (the part that means rights or clauses changed). */
    public static function newer(string $current, string $accepted): bool
    {
        $mm = static fn(string $v): string => implode('.', array_slice(explode('.', $v . '.0.0'), 0, 2));
        return version_compare($mm($current), $mm($accepted), '>');
    }

    /** Keyed hash of the client IP (not reversible without the key). */
    private function ipHash(): ?string
    {
        $ip = Yii::$app instanceof \yii\web\Application ? Yii::$app->request->getUserIP() : null;
        if ($ip === null || $ip === '') {
            return null;
        }
        $key = Yii::$app instanceof \yii\web\Application ? (string)Yii::$app->request->cookieValidationKey : '';
        return hash_hmac('sha256', $ip, $key !== '' ? $key : 'rapttor-legal');
    }
}
