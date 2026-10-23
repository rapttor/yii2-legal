<?php

declare(strict_types=1);

namespace rapttor\yii2legal\widgets;

use rapttor\yii2legal\Legal;
use Yii;
use yii\base\Widget;
use yii\helpers\Html;
use yii\helpers\Url;

/**
 * Cookie banner, rendered only when the site uses non-essential cookies, the visitor has not decided and does not send
 * Global Privacy Control. "Accept all" and "Reject all" are equal buttons. Put it once in the layout: `<?= CookieBanner::widget() ?>`.
 */
final class CookieBanner extends Widget
{
    public array $options = ['class' => 'legal-cookie-banner', 'role' => 'dialog'];

    public function run(): string
    {
        /** @var Legal $legal */
        $legal = Yii::$app->get('legal');
        $consent = $legal->consent();
        if (!$consent->needsBanner()) {
            return '';
        }
        $names = array_map(static fn(string $c): string => mb_strtolower($legal->t("consent.$c")), $consent->categories());
        $settings = Url::to(['/' . $legal->routePrefix . '/consent', 'return' => Yii::$app->request->url]);
        $form = Html::beginForm(['/' . $legal->routePrefix . '/consent'], 'post')
            . Html::hiddenInput('return', Yii::$app->request->url)
            . Html::tag('p', Html::encode($legal->t('consent.text', ['{categories}' => implode(', ', $names)])))
            . Html::tag('div',
                Html::submitButton(Html::encode($legal->t('consent.accept_all')), ['name' => 'action', 'value' => 'accept_all', 'class' => 'legal-btn'])
                . Html::submitButton(Html::encode($legal->t('consent.reject_all')), ['name' => 'action', 'value' => 'reject_all', 'class' => 'legal-btn'])
                . Html::a(Html::encode($legal->t('consent.settings')), $settings, ['class' => 'legal-btn-link']),
                ['class' => 'legal-cookie-actions'])
            . Html::endForm();
        return Html::tag('div', $form, $this->options + ['aria-label' => $legal->t('consent.title')]);
    }
}
