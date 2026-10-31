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
    /** Link to the page being shown in each language (`?lang=`) instead of to the legal documents: for the consents and request forms. */
    public bool $sameRoute = false;
    /** Show a flag next to each language name (default: on for same-route forms). Flags stand for countries, not languages: the name is always shown too. */
    public ?bool $flags = null;
    /** One compact row: flag on top, short language name in small type below (default: on for same-route forms). Scrolls sideways on a narrow screen instead of wrapping. */
    public ?bool $compact = null;
    /** @var array<string,string> language tag => flag emoji */
    public const FLAGS = [
        'en' => '🇬🇧', 'sh' => '⚫', 'de' => '🇩🇪', 'fr' => '🇫🇷', 'es' => '🇪🇸',
        'pt-BR' => '🇧🇷', 'it' => '🇮🇹', 'pl' => '🇵🇱', 'nl' => '🇳🇱', 'tr' => '🇹🇷', 'uk' => '🇺🇦', 'ru' => '🇷🇺', 'ar' => '🇸🇦', 'hi' => '🇮🇳',
        'ja' => '🇯🇵', 'ko' => '🇰🇷', 'zh-Hans' => '🇨🇳',
    ];
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
            $url = $this->sameRoute ? \yii\helpers\Url::current(['lang' => $l['tag']]) : ($doc !== null && $has ? $legal->url($doc, $l['tag']) : $this->indexUrl($legal, $l['tag']));
            $name = (string)($this->labels[$l['tag']] ?? $l['name']);
            $flagChar = ($this->flags ?? $this->sameRoute) && isset(self::FLAGS[$l['tag']]) ? self::FLAGS[$l['tag']] : '';
            $attrs = ['hreflang' => $l['tag'], 'lang' => $l['tag'], 'rel' => 'alternate', 'title' => $name] + ($l['current'] ? ['aria-current' => 'true'] : []);
            if ($this->compact ?? $this->sameRoute) { // flag on top, short name in small type below: fits many languages in one row
                $short = trim((string)preg_replace('/\s*\(.*\)/u', '', $name));
                $attrs['style'] = 'display:flex;flex-direction:column;align-items:center;gap:.1rem;text-decoration:none;min-width:3rem;line-height:1.1;'
                    . ($l['current'] ? 'font-weight:700;border-bottom:2px solid currentColor;padding-bottom:.15rem' : 'opacity:.8');
                $a = Html::a('<span aria-hidden="true" style="font-size:1.5rem;line-height:1.2">' . ($flagChar !== '' ? $flagChar : '&#9679;') . '</span><span style="font-size:.65rem;white-space:nowrap">' . Html::encode($short) . '</span>', $url, $attrs);
            } else {
                $a = Html::a(($flagChar !== '' ? '<span aria-hidden="true">' . $flagChar . '</span> ' : '') . Html::encode($name), $url, $attrs);
            }
            $items .= Html::tag('li', $a, $l['current'] ? ['class' => 'is-current'] : []);
        }
        $options = $this->options + (($this->compact ?? $this->sameRoute)
            ? ['style' => 'list-style:none;padding:0;margin:0 0 1rem;display:flex;gap:.25rem;flex-wrap:nowrap;justify-content:safe center;overflow-x:auto;scrollbar-width:thin']
            : []);
        return Html::tag('ul', $items, $options);
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
