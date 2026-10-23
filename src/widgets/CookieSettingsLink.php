<?php

declare(strict_types=1);

namespace rapttor\yii2legal\widgets;

use rapttor\yii2legal\Legal;
use Yii;
use yii\base\Widget;
use yii\helpers\Html;
use yii\helpers\Url;

/** Footer link that opens the cookie settings page, so visitors can change or withdraw their choice at any time. */
final class CookieSettingsLink extends Widget
{
    public array $options = ['class' => 'legal-cookie-link'];

    public function run(): string
    {
        /** @var Legal $legal */
        $legal = Yii::$app->get('legal');
        return Html::a(Html::encode($legal->t('cookie.link')), Url::to(['/' . $legal->routePrefix . '/consent', 'return' => Yii::$app->request->url]), $this->options);
    }
}
