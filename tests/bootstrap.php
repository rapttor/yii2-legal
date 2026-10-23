<?php

declare(strict_types=1);

// Standalone checkout: vendor/ next to this package; inside a host app: the app's vendor/ three levels up.
$autoload = getenv('LEGAL_TEST_AUTOLOAD') ?: (is_file(dirname(__DIR__) . '/vendor/autoload.php') ? dirname(__DIR__) . '/vendor/autoload.php' : dirname(__DIR__, 3) . '/vendor/autoload.php');
defined('YII_ENABLE_ERROR_HANDLER') or define('YII_ENABLE_ERROR_HANDLER', false);
defined('YII_DEBUG') or define('YII_DEBUG', true);
require $autoload;
require dirname($autoload) . '/yiisoft/yii2/Yii.php';

$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$runtime = sys_get_temp_dir() . '/yii2-legal-tests-' . getmypid();
@mkdir($runtime, 0777, true);
register_shutdown_function(static fn() => is_dir($runtime) && exec('rm -rf ' . escapeshellarg($runtime)));

require __DIR__ . '/TestIdentity.php';

new yii\web\Application([
    'id' => 'legal-tests',
    'basePath' => __DIR__,
    'runtimePath' => $runtime,
    'language' => 'en',
    'layout' => false,
    'aliases' => ['@rapttor/yii2legal' => dirname(__DIR__) . '/src'],
    'components' => [
        'db' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'user' => ['class' => yii\web\User::class, 'identityClass' => rapttor\yii2legal\tests\TestIdentity::class, 'enableSession' => false, 'loginUrl' => null],
        'request' => ['cookieValidationKey' => 'x', 'scriptUrl' => '/index.php', 'baseUrl' => '', 'enableCsrfValidation' => false, 'enableCookieValidation' => false],
        'urlManager' => ['enablePrettyUrl' => true, 'showScriptName' => false],
    ],
]);
