<?php

/** @var yii\web\View $this */
/** @var rapttor\yii2legal\Legal $legal */
/** @var string $return */

use yii\helpers\Html;

$consent = $legal->consent();
$this->title = $legal->t('cookie.link');
$decision = $consent->decision() ?? [];
?>
<section class="legal legal-consent-page">
    <h1><?= Html::encode($this->title) ?></h1>
    <?php if ($consent->gpc()): ?><p class="legal-notice" role="note"><?= Html::encode($legal->t('consent.gpc')) ?></p><?php endif ?>
    <?= Html::beginForm('', 'post') ?>
    <?= Html::hiddenInput('return', $return) ?>
    <p><label><input type="checkbox" checked disabled> <?= Html::encode($legal->t('consent.necessary')) ?></label></p>
    <?php foreach ($consent->categories() as $cat): ?>
        <p><label><?= Html::checkbox("c[$cat]", !$consent->gpc() && !empty($decision[$cat]), ['value' => '1', 'disabled' => $consent->gpc()]) ?> <?= Html::encode($legal->t("consent.$cat")) ?></label></p>
    <?php endforeach ?>
    <p>
        <?= Html::submitButton(Html::encode($legal->t('consent.save')), ['name' => 'action', 'value' => 'save', 'class' => 'legal-btn']) ?>
        <?= Html::submitButton(Html::encode($legal->t('consent.accept_all')), ['name' => 'action', 'value' => 'accept_all', 'class' => 'legal-btn']) ?>
        <?= Html::submitButton(Html::encode($legal->t('consent.reject_all')), ['name' => 'action', 'value' => 'reject_all', 'class' => 'legal-btn']) ?>
    </p>
    <?php if ($legal->has('cookies')): ?>
        <p><?= Html::a(Html::encode($legal->document('cookies')['title']), $legal->url('cookies')) ?></p>
    <?php endif ?>
    <?= Html::endForm() ?>
</section>
