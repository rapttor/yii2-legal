<?php

declare(strict_types=1);

namespace rapttor\yii2legal\filters;

use rapttor\yii2legal\Legal;
use Yii;
use yii\base\ActionFilter;

/**
 * Sends logged-in users to the acceptance page when a document they must accept has a new MAJOR/MINOR version.
 *
 *     'as legalAcceptance' => ['class' => rapttor\yii2legal\filters\AcceptanceFilter::class],   // in the app config or a base controller
 */
final class AcceptanceFilter extends ActionFilter
{
    /** Routes (fnmatch patterns) that never redirect: the legal pages, login and logout. @var list<string> */
    public array $exceptRoutes = ['legal/*', 'site/login', 'site/logout', 'site/error', 'debug/*', 'gii/*'];

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
        /** @var Legal $legal */
        $legal = Yii::$app->get('legal');
        if ($legal->acceptance()->pending((int)$user->id) === []) {
            return true;
        }
        Yii::$app->response->redirect(['/' . $legal->routePrefix . '/accept', 'return' => Yii::$app->request->url])->send();
        return false;
    }
}
