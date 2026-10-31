<?php

/** @var yii\web\View $this */
/** @var rapttor\yii2legal\Legal $legal */
/** @var list<array<string,mixed>> $pending */
/** @var string $return */
/** @var list<string> $missing */

use yii\helpers\Html;

$this->title = $legal->t('consents.title');
?>
<section class="legal legal-consents-page">
    <h1><?= Html::encode($this->title) ?></h1>
    <p><?= Html::encode($legal->t('consents.intro')) ?></p>
    <?php if ($missing !== []): ?><p class="legal-error" role="alert"><?= Html::encode($legal->t('consents.error')) ?></p><?php endif ?>
    <?= Html::beginForm('', 'post') ?>
    <?= Html::hiddenInput('return', $return) ?>
    <?php foreach ($pending as $d): ?>
        <p class="legal-consent-item<?= in_array($d['key'], $missing, true) ? ' legal-consent-missing' : '' ?>">
            <label>
                <?= Html::checkbox('consent[' . $d['key'] . ']', false, ['value' => '1']) ?>
                <?= Html::encode($d['label']) ?>
                <small>(<?= Html::encode($legal->t($d['required'] ? 'consents.required' : 'consents.optional')) ?>)</small>
            </label>
            <?php if ($d['url']): ?> <a href="<?= Html::encode($d['url']) ?>" target="_blank" rel="noopener"><?= Html::encode($legal->t('consents.read')) ?></a><?php endif ?>
        </p>
    <?php endforeach ?>
    <p><small><?= Html::encode($legal->t('consents.note')) ?></small></p>
    <p><?= Html::submitButton(Html::encode($legal->t('consents.button')), ['class' => 'legal-btn']) ?></p>
    <?= Html::endForm() ?>
</section>
