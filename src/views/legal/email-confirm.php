<?php

/** @var yii\web\View $this */
/** @var rapttor\yii2legal\Legal $legal */
/** @var string $email masked */
/** @var string $token */

use yii\helpers\Html;

$this->title = $legal->t('stop.title', ['{product}' => $legal->productName()]);
?>
<section class="legal legal-email-confirm">
    <h1><?= Html::encode($this->title) ?></h1>
    <p><?= Html::encode($legal->t('stop.confirm_text', ['{email}' => $email, '{product}' => $legal->productName()])) ?></p>
    <?= Html::beginForm('', 'post') ?>
    <p><?= Html::submitButton(Html::encode($legal->t('stop.confirm_button')), ['class' => 'legal-btn']) ?></p>
    <?= Html::endForm() ?>
</section>
