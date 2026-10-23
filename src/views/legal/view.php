<?php

/** @var yii\web\View $this */
/** @var rapttor\yii2legal\Legal $legal */
/** @var array<string,mixed> $entry */
/** @var string $html Sanitised by HTML Purifier in Legal::toHtml() */
/** @var string $lang requested language tag */
/** @var string|null $notice shown when the document is displayed in another language than requested */
/** @var string $dir ltr|rtl */
/** @var list<array{id:string,title:string}> $toc */

use yii\helpers\Html;

?>
<article class="legal legal-<?= Html::encode(str_replace('_', '-', $entry['id'])) ?>" lang="<?= Html::encode($entry['lang']) ?>" dir="<?= Html::encode($dir) ?>">
    <?php if ($notice !== null): ?>
        <p class="legal-notice" role="note"><?= Html::encode($notice) ?></p>
    <?php endif ?>
    <?php if ($toc !== []): ?>
        <nav class="legal-toc" aria-label="<?= Html::encode($legal->t('toc', [], $entry['lang'])) ?>">
            <strong><?= Html::encode($legal->t('toc', [], $entry['lang'])) ?></strong>
            <ol><?php foreach ($toc as $t): ?><li><a href="#<?= Html::encode($t['id']) ?>"><?= Html::encode($t['title']) ?></a></li><?php endforeach ?></ol>
        </nav>
    <?php endif ?>
    <?= $html ?>
    <footer class="legal-meta">
        <small><?= Html::encode($entry['title']) ?> v<?= Html::encode($entry['version']) ?><?= ($entry['status'] ?? 'reviewed') === 'draft' ? ' (' . Html::encode($legal->t('draft', [], $entry['lang'])) . ')' : '' ?></small>
    </footer>
</article>
