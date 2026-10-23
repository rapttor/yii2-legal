<?php

/** @var yii\web\View $this */
/** @var rapttor\yii2legal\Legal $legal */
/** @var string $lang */

use yii\helpers\Html;

$this->title = 'Legal';
?>
<section class="legal legal-index" lang="<?= Html::encode($lang) ?>" dir="<?= Html::encode($legal->library()->direction($lang)) ?>">
    <h1><?= Html::encode($this->title) ?></h1>
    <ul>
        <?php foreach ($legal->documents($lang) as $d): ?>
            <li><?= Html::a(Html::encode($d['title']), $legal->url($d['id'], $lang), ['hreflang' => $d['lang']]) ?></li>
        <?php endforeach ?>
    </ul>
</section>
