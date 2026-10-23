<?php

/** @var yii\web\View $this */
/** @var rapttor\yii2legal\Legal $legal */
/** @var list<array<string,mixed>> $pending */
/** @var string $return */
/** @var bool $error */

use yii\helpers\Html;

$this->title = $legal->t('accept.title');
?>
<section class="legal legal-accept-page">
    <h1><?= Html::encode($this->title) ?></h1>
    <p><?= Html::encode($legal->t('accept.intro')) ?></p>
    <?php if ($error): ?><p class="legal-error" role="alert"><?= Html::encode($legal->t('accept.required')) ?></p><?php endif ?>
    <?= Html::beginForm('', 'post') ?>
    <?= Html::hiddenInput('return', $return) ?>
    <?= rapttor\yii2legal\widgets\AcceptanceCheckbox::widget(['docs' => array_column($pending, 'id')]) ?>
    <p><?= Html::submitButton(Html::encode($legal->t('accept.button')), ['class' => 'legal-btn']) ?></p>
    <?= Html::endForm() ?>
</section>
