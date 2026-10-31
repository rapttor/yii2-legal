<?php

/** @var yii\web\View $this */
/** @var rapttor\yii2legal\Legal $legal */
/** @var string $prefill */

use yii\helpers\Html;

$this->title = $legal->t('stop.title', ['{product}' => $legal->productName()]);
?>
<section class="legal legal-email-stop">
    <h1><?= Html::encode($this->title) ?></h1>
    <p><?= Html::encode($legal->t('stop.text')) ?></p>
    <?= Html::beginForm('', 'post') ?>
    <p><label><?= Html::encode($legal->t('stop.email')) ?><br><?= Html::input('email', 'email', $prefill, ['required' => true, 'autocomplete' => 'email']) ?></label></p>
    <p><?= Html::submitButton(Html::encode($legal->t('stop.button')), ['class' => 'legal-btn']) ?></p>
    <?= Html::endForm() ?>
</section>
