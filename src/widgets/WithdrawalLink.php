<?php

declare(strict_types=1);

namespace rapttor\yii2legal\widgets;

use rapttor\yii2legal\Legal;
use Yii;
use yii\base\Widget;
use yii\helpers\Html;
use yii\helpers\Url;

/** "Withdraw from contract" function for the account page and the order confirmation (EU withdrawal function). */
final class WithdrawalLink extends Widget
{
    public array $options = ['class' => 'legal-withdraw-link'];

    public function run(): string
    {
        /** @var Legal $legal */
        $legal = Yii::$app->get('legal');
        return Html::a(Html::encode($legal->t('withdraw.link')), Url::to(['/' . $legal->routePrefix . '/withdraw']), $this->options);
    }
}
