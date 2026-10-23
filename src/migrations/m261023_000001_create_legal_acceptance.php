<?php

declare(strict_types=1);

namespace rapttor\yii2legal\migrations;

use rapttor\yii2legal\Acceptance;
use Yii;
use yii\db\Migration;

/** Table `legal_acceptance` (name configurable through Legal::$acceptanceTable). Run: php yii migrate */
final class m261023_000001_create_legal_acceptance extends Migration
{
    public function safeUp(): void
    {
        Acceptance::createTable($this->db, Yii::$app->get('legal')->acceptanceTable);
    }

    public function safeDown(): void
    {
        $this->dropTable(Yii::$app->get('legal')->acceptanceTable);
    }
}
