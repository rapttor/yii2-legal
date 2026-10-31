<?php

declare(strict_types=1);

namespace rapttor\yii2legal\migrations;

use rapttor\yii2legal\Schema;
use Yii;
use yii\db\Migration;

/** Creates the shared metadata table (consents live there) and adds `ua_hash` to the acceptance log. Safe to run on an existing install. */
final class m261031_000002_add_metadata_table extends Migration
{
    public function safeUp(): void
    {
        Schema::create($this->db, Yii::$app->get('legal'));
    }

    public function safeDown(): void
    {
        $this->dropColumn(Yii::$app->get('legal')->acceptanceTable, 'ua_hash');
    }
}
