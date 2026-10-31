<?php

declare(strict_types=1);

namespace rapttor\yii2legal;

use yii\db\Connection;

/** Tables of the package. Used by the migrations and by tests. */
final class Schema
{
    public static function create(Connection $db, Legal $legal): void
    {
        Acceptance::createTable($db, $legal->acceptanceTable);
        self::ensureContext($db, $legal->acceptanceTable);
        self::ensureColumn($db, $legal->acceptanceTable, 'ua_hash', 'string(64) NULL');
        self::table($db, $legal->consentTable, [
            'id' => 'pk', 'user_id' => 'string(64) NOT NULL', 'ckey' => 'string(64) NOT NULL', 'version' => 'string(32) NOT NULL',
            'granted' => 'boolean NOT NULL', 'at' => 'integer NOT NULL', 'lang' => 'string(12) NULL', 'source' => 'string(24) NULL', 'evidence' => 'text NULL',
        ], [['user_id', 'ckey']], true);
        self::table($db, $legal->suppressionTable, [
            'id' => 'pk', 'email' => 'string(254) NOT NULL', 'scope' => 'string(12) NOT NULL', 'source' => 'string(24) NULL', 'created_at' => 'integer NOT NULL',
        ], [['email', 'scope']], true);
        self::table($db, $legal->tokenTable, [
            'id' => 'pk', 'hash' => 'string(64) NOT NULL', 'purpose' => 'string(24) NOT NULL', 'email' => 'string(254) NOT NULL',
            'expires_at' => 'integer NOT NULL', 'used_at' => 'integer NULL', 'created_at' => 'integer NOT NULL',
        ], [['hash'], ['email', 'created_at']], false);
        self::table($db, $legal->withdrawalTable, [
            'id' => 'pk', 'contract' => 'string(190) NOT NULL', 'email' => 'string(254) NOT NULL', 'name' => 'string(190) NULL',
            'requested_at' => 'integer NOT NULL', 'ip_hash' => 'string(64) NULL', 'acknowledged_at' => 'integer NULL',
        ], [['email', 'contract']], false);
    }

    /** Add the `context` column to an acceptance table created by version 0.1.0. */
    public static function ensureContext(Connection $db, string $table): void
    {
        self::ensureColumn($db, $table, 'context', 'text NULL');
    }

    /** Add a column to an existing table when it is missing (for installs created by an earlier version). */
    public static function ensureColumn(Connection $db, string $table, string $column, string $type): void
    {
        $schema = $db->getTableSchema($table, true);
        if ($schema !== null && !isset($schema->columns[$column])) {
            $db->createCommand()->addColumn($table, $column, $type)->execute();
        }
    }

    /** @param array<string,string> $columns @param list<list<string>> $indexes */
    private static function table(Connection $db, string $table, array $columns, array $indexes, bool $firstUnique): void
    {
        if ($db->getTableSchema($table, true) !== null) {
            return;
        }
        $db->createCommand()->createTable($table, $columns)->execute();
        foreach ($indexes as $i => $cols) {
            $name = 'idx_' . preg_replace('/\W+/', '_', trim($table, '{}%')) . '_' . $i;
            $db->createCommand()->createIndex($name, $table, $cols, $i === 0 && $firstUnique)->execute();
        }
    }
}
