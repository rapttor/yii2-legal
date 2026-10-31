<?php

/** @var yii\web\View $this */
/** @var rapttor\yii2legal\Legal $legal */
/** @var string $lang */

use yii\helpers\Html;

$this->title = $legal->t('index.title', [], $lang);
?>
<section class="legal legal-index" lang="<?= Html::encode($lang) ?>" dir="<?= Html::encode($legal->library()->direction($lang)) ?>">
    <?= rapttor\yii2legal\widgets\LanguageSwitcher::widget(['compact' => true, 'flags' => true, 'options' => ['class' => 'legal-languages legal-languages-top']]) ?>
    <h1><?= Html::encode($this->title) ?></h1>
    <ul>
        <?php foreach ($legal->links($lang) as $l): ?>
            <li><?= Html::a(Html::encode($l['title']), $l['url'], ['hreflang' => $l['lang']]) ?></li>
        <?php endforeach ?>
    </ul>
</section>
