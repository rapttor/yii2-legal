<?php

declare(strict_types=1);

namespace rapttor\yii2legal\migrations;

use rapttor\yii2legal\Schema;
use Yii;
use yii\db\Migration;

/** Tables for the consent registry, email suppression and tokens, withdrawals; adds `context` to the acceptance log. */
final class m261031_000001_create_consent_email_withdrawal extends Migration
{
    public function safeUp(): void
    {
        Schema::create($this->db, Yii::$app->get('legal'));
    }

    public function safeDown(): void
    {
        $legal = Yii::$app->get('legal');
        foreach ([$legal->withdrawalTable, $legal->tokenTable, $legal->suppressionTable, $legal->consentTable] as $t) {
            $this->dropTable($t);
        }
    }
}
