<?php

/** @var yii\web\View $this */
/** @var rapttor\yii2legal\Legal $legal */
/** @var string $email masked */

use yii\helpers\Html;

$this->title = $legal->t('unsub.title');
?>
<section class="legal legal-unsubscribe">
    <h1><?= Html::encode($this->title) ?></h1>
    <p><?= Html::encode($legal->t('unsub.text', ['{email}' => $email])) ?></p>
    <?= Html::beginForm('', 'post') ?>
    <p><?= Html::submitButton(Html::encode($legal->t('unsub.button')), ['class' => 'legal-btn']) ?></p>
    <?= Html::endForm() ?>
</section>
