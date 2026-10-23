<?php

declare(strict_types=1);

namespace rapttor\yii2legal\widgets;

use rapttor\yii2legal\Legal;
use Yii;
use yii\base\Widget;
use yii\helpers\Html;

/**
 * Language switcher for the legal pages: `<?= LanguageSwitcher::widget() ?>`.
 *
 * By default it lists every language the `legal` component offers (its `languages` option). Narrow or reorder it per
 * placement with `only`, rename entries with `labels`. On a document page each link goes to the same document in that
 * language; elsewhere to the legal index in that language.
 */
final class LanguageSwitcher extends Widget
{
    /** @var list<string>|null Tags to show, in this order; null = every offered language. Tags the site does not offer are skipped. */
    public ?array $only = null;
    /** @var array<string,string> tag => label overrides (default: the language's own name, e.g. "Hrvatski"). */
    public array $labels = [];
    /** Document id to link to; null = the document being shown, else the index. */
    public ?string $doc = null;
    /** Do not render a switcher with a single language. */
    public bool $hideIfSingle = true;
    public array $options = ['class' => 'legal-languages'];

    public function run(): string
    {
        /** @var Legal $legal */
        $legal = Yii::$app->get('legal');
        $list = $legal->languageList();
        if ($this->only !== null) {
            $byTag = array_column($list, null, 'tag');
            $list = [];
            foreach ($this->only as $t) {
                $tag = $legal->library()->normalizeLanguage($t);
                if ($tag !== null && isset($byTag[$tag])) {
                    $list[$tag] = $byTag[$tag];
                }
            }
            $list = array_values($list);
        }
        if ($list === [] || ($this->hideIfSingle && count($list) < 2)) {
            return '';
        }
        $doc = $this->doc ?? $this->currentDocument();
        $items = '';
        foreach ($list as $l) {
            $has = $doc !== null && $legal->document($doc, $l['tag']) !== null && !$legal->document($doc, $l['tag'])['fallback'];
            $url = $doc !== null && $has ? $legal->url($doc, $l['tag']) : $this->indexUrl($legal, $l['tag']);
            $a = Html::a(Html::encode($this->labels[$l['tag']] ?? $l['name']), $url, [
                'hreflang' => $l['tag'], 'lang' => $l['tag'], 'rel' => 'alternate',
            ] + ($l['current'] ? ['aria-current' => 'true'] : []));
            $items .= Html::tag('li', $a, $l['current'] ? ['class' => 'is-current'] : []);
        }
        return Html::tag('ul', $items, $this->options);
    }

    private function currentDocument(): ?string
    {
        $c = Yii::$app->controller;
        $slug = $c !== null ? ($c->actionParams['doc'] ?? null) : null;
        return is_string($slug) ? Legal::idFromSlug($slug) : null;
    }

    private function indexUrl(Legal $legal, string $tag): string
    {
        return \yii\helpers\Url::to(['/' . $legal->routePrefix . '/' . ($tag === $legal->defaultUrlLanguage() ? '' : $tag)]);
    }
}
