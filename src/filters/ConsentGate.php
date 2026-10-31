<?php

declare(strict_types=1);

namespace rapttor\yii2legal\filters;

use rapttor\yii2legal\Legal;
use Yii;
use yii\base\ActionFilter;

/**
 * Sends signed-in users (anonymous accounts too) to the consents checklist while any consent is missing or has a new
 * version: required ones block use, optional ones are asked once per version.
 *
 *     'as legalConsents' => ['class' => rapttor\yii2legal\filters\ConsentGate::class],
 */
class ConsentGate extends ActionFilter
{
    /** Routes (fnmatch patterns) that never redirect. @var list<string> */
    public array $exceptRoutes = ['legal/*', 'site/login', 'site/logout', 'site/error', 'debug/*', 'gii/*'];

    /** Roles or permissions (`Yii::$app->user->can()`) that skip the gate: your own operator accounts. Tenant admins and everybody else are asked. @var list<string> */
    public array $exceptCan = [];

    public function beforeAction($action): bool
    {
        $user = Yii::$app->get('user', false);
        if ($user === null || $user->isGuest || !Yii::$app->has('legal')) {
            return true;
        }
        foreach ($this->exceptRoutes as $pattern) {
            if (fnmatch($pattern, $action->uniqueId)) {
                return true;
            }
        }
        foreach ($this->exceptCan as $perm) {
            if ($user->can($perm)) {
                return true;
            }
        }
        /** @var Legal $legal */
        $legal = Yii::$app->get('legal');
        if ($legal->consents()->pending($user->id) === []) {
            return true;
        }
        Yii::$app->response->redirect(['/' . $legal->routePrefix . '/consents', 'return' => Yii::$app->request->url]); // Yii sends this redirect once the filter returns false
        return false;
    }
}
