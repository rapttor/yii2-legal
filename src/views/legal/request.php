<?php

/** @var yii\web\View $this */
/** @var rapttor\yii2legal\Legal $legal */
/** @var array<string,string> $v */
/** @var string|null $error */

use rapttor\yii2legal\Requests;
use yii\helpers\Html;

$this->title = $legal->t('request.title');
?>
<section class="legal legal-request">
    <?= rapttor\yii2legal\widgets\LanguageSwitcher::widget(['sameRoute' => true]) ?>
    <h1><?= Html::encode($this->title) ?></h1>
    <p><?= Html::encode($legal->t('request.intro')) ?></p>
    <?php if ($error): ?><p class="legal-error" role="alert"><?= Html::encode($legal->t('request.error_' . $error)) ?></p><?php endif ?>
    <?= Html::beginForm('', 'post') ?>
    <p><label><?= Html::encode($legal->t('request.type')) ?><br>
        <select name="type"><?php foreach (Requests::TYPES as $t): ?><option value="<?= $t ?>"<?= $v['type'] === $t ? ' selected' : '' ?>><?= Html::encode($legal->t('request.type.' . $t)) ?></option><?php endforeach ?></select></label></p>
    <p><label><?= Html::encode($legal->t('request.name')) ?><br><?= Html::input('text', 'name', $v['name'], ['autocomplete' => 'name']) ?></label></p>
    <p><label><?= Html::encode($legal->t('request.email')) ?><br><?= Html::input('email', 'email', $v['email'], ['required' => true, 'autocomplete' => 'email']) ?></label></p>
    <p><label><?= Html::encode($legal->t('request.message')) ?><br><?= Html::textarea('message', $v['message'], ['rows' => 6, 'required' => true, 'maxlength' => 8000]) ?></label></p>
    <p style="position:absolute;left:-9999px" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>
    <p><?= Html::submitButton(Html::encode($legal->t('request.send')), ['class' => 'legal-btn']) ?></p>
    <?= Html::endForm() ?>
</section>
