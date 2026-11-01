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
    /**
     * Languages with a picture instead of a country flag: tag => PNG (base64) used as a mask, drawn in the text colour.
     * `sh` is the Balkan region as one neutral silhouette (no flag, no national symbol; from balkan-bw.svg, see NOTICE).
     */
    public const FLAG_MASKS = ['sh' => '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'];

    /** @var array<string,string> language tag => flag emoji */
    public const FLAGS = [
        'en' => '🇬🇧', 'de' => '🇩🇪', 'fr' => '🇫🇷', 'es' => '🇪🇸',
        'es-MX' => '🇲🇽', 'sv' => '🇸🇪', 'ro' => '🇷🇴', 'id' => '🇮🇩', 'vi' => '🇻🇳', 'pt-BR' => '🇧🇷', 'it' => '🇮🇹', 'pl' => '🇵🇱', 'nl' => '🇳🇱', 'tr' => '🇹🇷', 'uk' => '🇺🇦', 'ru' => '🇷🇺', 'ar' => '🇸🇦', 'hi' => '🇮🇳',
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
        $shorts = [];
        foreach ($list as $l) { // short names for the compact strip; two languages that would show the same word (Español, Español (México)) show the part in brackets instead
            $full = (string)($this->labels[$l['tag']] ?? $l['name']);
            $s = trim((string)preg_replace('/\s*\(.*\)/u', '', $full));
            $shorts[$l['tag']] = [$s, preg_match('/\((.*)\)/u', $full, $m) ? $m[1] : $s];
        }
        $count = array_count_values(array_column($shorts, 0));
        foreach ($list as $l) {
            $has = $doc !== null && $legal->document($doc, $l['tag']) !== null && !$legal->document($doc, $l['tag'])['fallback'];
            $url = $this->sameRoute ? \yii\helpers\Url::current(['lang' => $l['tag']]) : ($doc !== null && $has ? $legal->url($doc, $l['tag']) : $this->indexUrl($legal, $l['tag']));
            $name = (string)($this->labels[$l['tag']] ?? $l['name']);
            $flagChar = ($this->flags ?? $this->sameRoute) && isset(self::FLAGS[$l['tag']]) ? self::FLAGS[$l['tag']] : '';
            $mask = ($this->flags ?? $this->sameRoute) ? self::FLAG_MASKS[$l['tag']] ?? null : null;
            $attrs = ['hreflang' => $l['tag'], 'lang' => $l['tag'], 'rel' => 'alternate', 'title' => $name] + ($l['current'] ? ['aria-current' => 'true'] : []);
            if ($this->compact ?? $this->sameRoute) { // flag on top, short name in small type below: fits many languages in one row
                [$short, $inner] = $shorts[$l['tag']];
                $short = $count[$short] > 1 && $inner !== $short && $l['tag'] !== array_key_first(array_filter($shorts, static fn(array $x): bool => $x[0] === $short)) ? $inner : $short;
                $attrs['style'] = 'display:flex;flex-direction:column;align-items:center;gap:.1rem;text-decoration:none;min-width:3rem;line-height:1.1;'
                    . ($l['current'] ? 'font-weight:700;border-bottom:2px solid currentColor;padding-bottom:.15rem' : 'opacity:.8');
                $a = Html::a('<span aria-hidden="true" style="font-size:1.5rem;line-height:1.2">' . ($mask !== null ? self::maskIcon($mask, '1.9rem', '1.27rem') : ($flagChar !== '' ? $flagChar : '&#9679;')) . '</span><span style="font-size:.65rem;white-space:nowrap">' . Html::encode($short) . '</span>', $url, $attrs);
            } else {
                $a = Html::a(($mask !== null ? '<span aria-hidden="true">' . self::maskIcon($mask, '1.3em', '.87em') . '</span> ' : ($flagChar !== '' ? '<span aria-hidden="true">' . $flagChar . '</span> ' : '')) . Html::encode($name), $url, $attrs);
            }
            $items .= Html::tag('li', $a, $l['current'] ? ['class' => 'is-current'] : []);
        }
        $options = $this->options + (($this->compact ?? $this->sameRoute)
            ? ['style' => 'list-style:none;padding:0;margin:0 0 1rem;display:flex;gap:.25rem;flex-wrap:nowrap;justify-content:safe center;overflow-x:auto;scrollbar-width:thin']
            : []);
        return Html::tag('ul', $items, $options);
    }

    /** A picture drawn through a mask in the current text colour (works on light and dark pages). */
    private static function maskIcon(string $pngBase64, string $w, string $h): string
    {
        $u = 'url(data:image/png;base64,' . $pngBase64 . ')';
        return '<span style="display:inline-block;vertical-align:middle;width:' . $w . ';height:' . $h . ';background:currentColor;-webkit-mask:' . $u . ' center/contain no-repeat;mask:' . $u . ' center/contain no-repeat"></span>';
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
