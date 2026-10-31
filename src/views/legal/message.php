<?php

/** @var yii\web\View $this */
/** @var rapttor\yii2legal\Legal $legal */
/** @var string $title */
/** @var string $text */

use yii\helpers\Html;

$this->title = $title;
?>
<section class="legal legal-message">
    <h1><?= Html::encode($title) ?></h1>
    <p><?= Html::encode($text) ?></p>
</section>
