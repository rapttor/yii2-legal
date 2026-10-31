<?php

declare(strict_types=1);

namespace rapttor\yii2legal\migrations;

use rapttor\yii2legal\Schema;
use Yii;
use yii\db\Migration;

/** Table for privacy and legal requests (name: Legal::$requestTable). */
final class m261101_000001_create_legal_request extends Migration
{
    public function safeUp(): void
    {
        Schema::create($this->db, Yii::$app->get('legal'));
    }

    public function safeDown(): void
    {
        $this->dropTable(Yii::$app->get('legal')->requestTable);
    }
}
