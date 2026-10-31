<?php

/** @var yii\web\View $this */
/** @var rapttor\yii2legal\Legal $legal */
/** @var list<array<string,mixed>> $pending */
/** @var string $return */
/** @var list<string> $missing */

use yii\helpers\Html;

$this->title = $legal->t('consents.title');
?>
<section class="legal legal-consents-page" lang="<?= Html::encode($legal->currentLanguage()) ?>" dir="<?= Html::encode($legal->library()->direction($legal->currentLanguage())) ?>">
    <?= rapttor\yii2legal\widgets\LanguageSwitcher::widget(['sameRoute' => true]) ?>
    <h1><?= Html::encode($this->title) ?></h1>
    <p><?= Html::encode($legal->t('consents.intro')) ?></p>
    <?php if ($missing !== []): ?><p class="legal-error" role="alert"><?= Html::encode($legal->t('consents.error')) ?></p><?php endif ?>
    <?= Html::beginForm('', 'post') ?>
    <?= Html::hiddenInput('return', $return) ?>
    <?= rapttor\yii2legal\widgets\ConsentChecklist::widget(['items' => $pending, 'missing' => $missing]) ?>
    <p><small><?= Html::encode($legal->t('consents.note')) ?></small></p>
    <p><?= Html::submitButton(Html::encode($legal->t('consents.button')), ['class' => 'legal-btn']) ?></p>
    <?= Html::endForm() ?>
</section>
