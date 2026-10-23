<?php

declare(strict_types=1);

namespace rapttor\yii2legal;

use yii\base\BootstrapInterface;
use yii\console\Application as ConsoleApplication;
use yii\web\Application as WebApplication;

/**
 * Runs through Composer's extension bootstrap. Web: maps the `legal` controller and adds the page routes
 * (/legal, /legal/<document>). Console: adds `legal/check` and `legal/export`. Does nothing until a `legal` component is configured.
 */
final class Bootstrap implements BootstrapInterface
{
    public function bootstrap($app): void
    {
        if (!$app->has('legal')) {
            return;
        }
        /** @var Legal $legal */
        $legal = $app->get('legal');
        if ($app instanceof WebApplication) {
            $app->controllerMap[$legal->routePrefix] ??= ['class' => controllers\LegalController::class];
            // Language rules first, limited to real language tags, so /legal/sr is the Serbian index and not a document called "sr".
            $tags = implode('|', array_map('preg_quote', array_keys($legal->library()->languages())));
            $p = $legal->routePrefix;
            $app->getUrlManager()->addRules([
                "$p/accept" => "$p/accept",
                "$p/consent" => "$p/consent",
                "$p/<lang:($tags)>/<doc:[a-z0-9-]+>" => "$p/view",
                "$p/<lang:($tags)>" => "$p/index",
                "$p/<doc:[a-z0-9-]+>" => "$p/view",
                $p => "$p/index",
            ], false);
        } elseif ($app instanceof ConsoleApplication) {
            $app->controllerMap['legal'] ??= ['class' => console\LegalController::class];
            $migrate = $app->controllerMap['migrate'] ?? ['class' => \yii\console\controllers\MigrateController::class];
            if (is_array($migrate)) {
                $migrate['migrationNamespaces'] = array_values(array_unique([...($migrate['migrationNamespaces'] ?? []), 'rapttor\yii2legal\migrations']));
                $app->controllerMap['migrate'] = $migrate;
            }
        }
    }
}
