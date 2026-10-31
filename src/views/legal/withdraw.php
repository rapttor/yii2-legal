<?php

/** @var yii\web\View $this */
/** @var rapttor\yii2legal\Legal $legal */
/** @var array<string,string> $v */
/** @var bool $review */
/** @var bool $error */

use yii\helpers\Html;

$this->title = $legal->t('withdraw.title');
?>
<section class="legal legal-withdraw">
    <h1><?= Html::encode($this->title) ?></h1>
    <?php if ($review): ?>
        <h2><?= Html::encode($legal->t('withdraw.confirm_title')) ?></h2>
        <p><?= Html::encode($legal->t('withdraw.confirm_text', ['{contract}' => $v['contract'], '{email}' => $v['email']])) ?></p>
        <?= Html::beginForm('', 'post') ?>
        <?php foreach ($v as $k => $val): ?><?= Html::hiddenInput($k, $val) ?><?php endforeach ?>
        <?= Html::hiddenInput('step', 'confirm') ?>
        <p><?= Html::submitButton(Html::encode($legal->t('withdraw.confirm')), ['class' => 'legal-btn']) ?></p>
        <?= Html::endForm() ?>
    <?php else: ?>
        <p><?= Html::encode($legal->t('withdraw.intro')) ?></p>
        <?php if ($error): ?><p class="legal-error" role="alert"><?= Html::encode($legal->t('withdraw.error')) ?></p><?php endif ?>
        <?= Html::beginForm('', 'post') ?>
        <?= Html::hiddenInput('step', 'review') ?>
        <p><label><?= Html::encode($legal->t('withdraw.name')) ?><br><?= Html::input('text', 'name', $v['name']) ?></label></p>
        <p><label><?= Html::encode($legal->t('withdraw.email')) ?><br><?= Html::input('email', 'email', $v['email'], ['required' => true]) ?></label></p>
        <p><label><?= Html::encode($legal->t('withdraw.contract')) ?><br><?= Html::input('text', 'contract', $v['contract'], ['required' => true]) ?></label></p>
        <p><?= Html::submitButton(Html::encode($legal->t('withdraw.review')), ['class' => 'legal-btn']) ?></p>
        <?= Html::endForm() ?>
    <?php endif ?>
</section>
