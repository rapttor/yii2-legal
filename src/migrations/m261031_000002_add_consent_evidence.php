<?php

declare(strict_types=1);

namespace rapttor\yii2legal\migrations;

use rapttor\yii2legal\Schema;
use Yii;
use yii\db\Migration;

/** Adds `evidence` (hashes of IP, browser, wording and document text, plus earlier acceptances) to the consent table and `ua_hash` to the acceptance log. */
final class m261031_000002_add_consent_evidence extends Migration
{
    public function safeUp(): void
    {
        $legal = Yii::$app->get('legal');
        Schema::ensureColumn($this->db, $legal->consentTable, 'evidence', 'text NULL');
        Schema::ensureColumn($this->db, $legal->acceptanceTable, 'ua_hash', 'string(64) NULL');
    }

    public function safeDown(): void
    {
        $legal = Yii::$app->get('legal');
        $this->dropColumn($legal->consentTable, 'evidence');
        $this->dropColumn($legal->acceptanceTable, 'ua_hash');
    }
}
