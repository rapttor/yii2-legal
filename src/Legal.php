<?php

declare(strict_types=1);

namespace rapttor\yii2legal;

use rapttor\legal\Library;
use rapttor\legal\Renderer;
use Yii;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\helpers\FileHelper;
use yii\helpers\HtmlPurifier;
use yii\helpers\Markdown;
use yii\helpers\Url;

/**
 * Application component: turns the bundled `rapttor/legal` templates plus ONE site config into sanitised HTML pages.
 *
 *     'components' => ['legal' => [
 *         'class' => rapttor\yii2legal\Legal::class,
 *         'config' => require __DIR__ . '/legal.config.php',   // or the JSON written by `vendor/bin/init.php`
 *     ]],
 *
 * Every document is rendered from the same config, so a changed email or address appears in all of them.
 */
class Legal extends Component
{
    /** @var array<string,mixed> Nested site config (company.*, feature.*, law.*, policy.*...). See rapttor/legal placeholders.json. */
    public array $config = [];
    /** Path to a JSON config file; merged under `config` (values in `config` win). Aliases allowed. */
    public ?string $configFile = null;
    /** URL prefix of the pages: /legal/privacy, /legal/acceptable-use ... */
    public string $routePrefix = 'legal';
    /** Language of the documents when the URL does not name one; defaults to the application language ("sr-RS" becomes "sr"). */
    public ?string $language = null;
    /**
     * Languages this site offers, in switcher order, for example ['en', 'sr', 'sr-Cyrl', 'hr']. null = every language
     * rapttor/legal bundles (reviewed documents only, unless $publishDrafts). Tags outside the bundle are ignored.
     *
     * @var list<string>|null
     */
    public ?array $languages = null;
    /** Show translations whose status is still "draft". Keep false in production: drafts are machine translations awaiting a lawyer's review. */
    public bool $publishDrafts = false;
    /** Shown above a document that is displayed in another language than requested. Placeholders: {requested}, {actual}. */
    public string $fallbackNotice = 'This document is not available in {requested} yet. You are reading the {actual} version.';
    /** Cache component id, or null to disable caching. */
    public ?string $cache = 'cache';
    /** Keep a Markdown copy of every distinct published render (what was in force, and when). */
    public bool $archive = true;
    /** Where archived renders are written. */
    public string $archivePath = '@runtime/legal';
    /** Library root override (tests); null = the installed rapttor/legal package. */
    public ?string $libraryRoot = null;
    /** HTML Purifier options merged over the safe defaults. */
    public array $purifier = [];
    /** Claim ids the owner has confirmed (see rapttor/legal claims.json); `yii legal/check` reports the others. */
    public array $confirmedClaims = [];
    /** Serve the last approved version of a document until `yii legal/approve` after a legal update. */
    public bool $requireApproval = false;
    /** Documents users must accept (sign-up, and again when a new MAJOR/MINOR version comes into force). */
    public array $acceptDocs = ['terms', 'privacy'];
    /** Database component id and table of the acceptance log. */
    public string $db = 'db';
    public string $acceptanceTable = '{{%legal_acceptance}}';
    /** Name of the cookie that stores the visitor's cookie choices. */
    public string $consentCookie = 'legal_consent';

    private ?Library $lib = null;
    /** @var array<string,mixed>|null */
    private ?array $effective = null;

    public function init(): void
    {
        parent::init();
        if (!class_exists(Library::class)) {
            throw new InvalidConfigException('rapttor/legal is not installed: composer require rapttor/legal');
        }
    }

    public function acceptance(): Acceptance
    {
        return $this->acceptance ??= new Acceptance($this);
    }

    public function consent(): Consent
    {
        return $this->consent ??= new Consent($this);
    }

    public function approval(): Approval
    {
        return $this->approval ??= new Approval($this);
    }

    private ?Acceptance $acceptance = null;
    private ?Consent $consent = null;
    private ?Approval $approval = null;
    /** @var array<string,array<string,string>>|null */
    private static ?array $messages = null;

    /** UI string in a legal-page language ("sr-Cyrl" is transliterated from "sr"); English if missing. @param array<string,string> $params */
    public function t(string $key, array $params = [], ?string $lang = null): string
    {
        self::$messages ??= require __DIR__ . '/messages.php';
        $lang ??= $this->currentLanguage();
        $base = $this->library()->normalizeLanguage($lang) ?? 'en';
        $cyr = $base === 'sr-Cyrl';
        $base = $cyr ? 'sr' : $base;
        $text = self::$messages[$base][$key] ?? self::$messages['en'][$key] ?? $key;
        $text = strtr($text, $params);
        return $cyr ? (new \rapttor\legal\Transliterator($this->libraryRoot))->toCyrillic($text) : $text;
    }

    /** Claims the published documents make about the product, with whether the owner confirmed them. @return list<array<string,mixed>> */
    public function claims(): array
    {
        return $this->library()->claims($this->effectiveConfig(), $this->confirmedClaims, $this->currentLanguage(), $this->publishDrafts);
    }

    public function library(): Library
    {
        return $this->lib ??= new Library($this->libraryRoot);
    }

    /** Effective config: configFile under config. @return array<string,mixed> */
    public function effectiveConfig(): array
    {
        if ($this->effective !== null) {
            return $this->effective;
        }
        $file = [];
        if ($this->configFile !== null) {
            $path = Yii::getAlias($this->configFile);
            if (!is_file($path)) {
                throw new InvalidConfigException("Legal config file not found: $path");
            }
            $file = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        }
        return $this->effective = array_replace_recursive($file, $this->config);
    }

    /** The bundled legal release in force (store it next to your own publication records). */
    public function release(): string
    {
        return $this->library()->release();
    }

    /** @return list<array<string,mixed>> Documents this site publishes in a language (default: the current one). */
    public function documents(?string $lang = null): array
    {
        return $this->library()->published($this->effectiveConfig(), $lang ?? $this->currentLanguage(), $this->publishDrafts);
    }

    public function has(string $id, ?string $lang = null): bool
    {
        return $this->document($id, $lang) !== null;
    }

    /** @return array<string,mixed>|null manifest entry; `lang` is the language it is really in, `fallback` says it differs from the requested one */
    public function document(string $id, ?string $lang = null): ?array
    {
        foreach ($this->documents($lang) as $d) {
            if ($d['id'] === $id) {
                return $d;
            }
        }
        return null;
    }

    /** Required config values still missing for the published documents. @return list<string> */
    public function missing(): array
    {
        return $this->library()->missingRequired($this->effectiveConfig(), $this->currentLanguage(), $this->publishDrafts);
    }

    // ---- languages ---------------------------------------------------------------------------------------------

    /**
     * The languages this site offers, in order. @return list<string> tags
     */
    public function offered(): array
    {
        $available = $this->library()->availableLanguages($this->publishDrafts);
        if ($this->languages === null) {
            return $available;
        }
        $out = [];
        foreach ($this->languages as $t) {
            $tag = $this->library()->normalizeLanguage($t);
            if ($tag !== null && in_array($tag, $available, true) && !in_array($tag, $out, true)) {
                $out[] = $tag;
            }
        }
        return $out === [] ? [$this->library()->resolveLanguage('en', $this->publishDrafts)] : $out;
    }

    /** Offered languages with names and direction, for switchers. @return list<array{tag:string,name:string,dir:string,current:bool}> */
    public function languageList(?string $current = null): array
    {
        $current ??= $this->currentLanguage();
        return array_map(fn(string $t): array => [
            'tag' => $t, 'name' => $this->library()->languageName($t), 'dir' => $this->library()->direction($t), 'current' => $t === $current,
        ], $this->offered());
    }

    /** The language of the page being served: ?lang from the route if given, else $language, else the application language. */
    public function currentLanguage(): string
    {
        return $this->forced ?? $this->resolve($this->language ?? (string)Yii::$app->language);
    }

    /** Resolve any tag ("sr_RS", "de-AT") to an offered language, following the fallback chain, finally English. */
    public function resolve(string $requested): string
    {
        return $this->library()->resolveLanguage($requested, $this->publishDrafts, $this->offered());
    }

    /** Language the controller selected from the URL; null = none. */
    private ?string $forced = null;

    public function forceLanguage(?string $tag): void
    {
        $this->forced = $tag;
    }

    /** The language used when the URL has no language segment. */
    public function defaultUrlLanguage(): string
    {
        return $this->resolve($this->language ?? (string)Yii::$app->language);
    }

    /** Public URL of a document. Without $lang it is in the page's current language; the default language has no language segment. */
    public function url(string $id, ?string $lang = null, bool $scheme = false): string
    {
        $lang ??= $this->currentLanguage();
        $route = '/' . $this->routePrefix . '/' . ($lang === $this->defaultUrlLanguage() ? '' : $lang . '/') . self::slug($id);
        return Url::to([$route], $scheme);
    }

    public static function slug(string $id): string
    {
        return str_replace('_', '-', $id);
    }

    public static function idFromSlug(string $slug): string
    {
        return str_replace('-', '_', $slug);
    }

    /** Rendered Markdown (placeholders resolved, not yet HTML). Throws if required config is missing and nothing was archived. */
    public function markdown(string $id, ?string $lang = null): string
    {
        $lang ??= $this->currentLanguage();
        $entry = $this->document($id, $lang);
        if ($this->requireApproval && $entry !== null && !$entry['fallback']) {
            $approval = $this->approval();
            if ($approval->state() === null) {
                $approval->approveCurrent(); // first run: the state at deployment is the approved one
            } else {
                $ok = $approval->approvedVersion($entry['lang'], $id);
                if ($ok !== null && $ok !== $entry['version']) {
                    $old = $this->latestArchived($id, $lang, $ok);
                    if ($old !== null) {
                        return $old; // new version awaits `yii legal/approve`
                    }
                }
            }
        }
        $missing = [];
        $text = $this->library()->render(
            $this->escapedConfig(),
            $id,
            fn(string $docId, string $docLang): string => $this->url($docId, $docLang),
            $lang,
            $missing,
            $this->publishDrafts
        );
        if ($missing !== []) {
            // Keep the previous good render instead of publishing "[company.name]" markers.
            $previous = $this->latestArchived($id, $lang);
            if ($previous !== null) {
                Yii::warning("Legal document '$id' has missing config (" . implode(', ', $missing) . '); serving the last archived render.', __METHOD__);
                return $previous;
            }
            throw new InvalidConfigException("Legal config is missing: " . implode(', ', $missing));
        }
        if ($this->archive) {
            $this->archiveRender($id, $text, $entry['lang'] ?? $lang, $entry['version'] ?? '0.0.0');
        }
        return $text;
    }

    /** Sanitised HTML of a document (cached). */
    public function html(string $id, ?string $lang = null): string
    {
        $lang ??= $this->currentLanguage();
        $key = null;
        $cache = $this->cache !== null && Yii::$app->has($this->cache) ? Yii::$app->get($this->cache) : null;
        if ($cache !== null) {
            $key = ['rapttor-legal', $this->release(), $id, $lang, $this->routePrefix, $this->publishDrafts, md5(json_encode($this->effectiveConfig(), JSON_THROW_ON_ERROR))];
            $hit = $cache->get($key);
            if (is_string($hit)) {
                return $hit;
            }
        }
        $html = $this->toHtml($this->markdown($id, $lang));
        if ($cache !== null) {
            $cache->set($key, $html, 86400);
        }
        return $html;
    }

    /** Markdown (GFM, so tables work) to HTML, then through HTML Purifier: config values are untrusted. */
    public function toHtml(string $markdown): string
    {
        $html = Markdown::process($markdown, 'gfm');
        // "## Title {#anchor}" -> <h2 id="anchor">Title</h2>
        $html = (string)preg_replace('/<(h[1-6])>(.*?)\s*\{#([a-z0-9-]+)\}<\/\1>/s', '<$1 id="$3">$2</$1>', $html);
        $options = array_replace([
            'Attr.EnableID' => true,
            'HTML.Nofollow' => true,
            'URI.AllowedSchemes' => ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true],
            'Cache.SerializerPath' => $this->purifierCache(),
        ], $this->purifier);
        return HtmlPurifier::process($html, static function ($config) use ($options): void {
            foreach ($options as $k => $v) {
                $config->set($k, $v);
            }
        });
    }

    /** Escape `<` `>` in config values (not in table.* blocks): they must never become markup. @return array<string,mixed> */
    private function escapedConfig(): array
    {
        $walk = static function (array $a, string $path) use (&$walk): array {
            foreach ($a as $k => $v) {
                $p = $path === '' ? (string)$k : "$path.$k";
                if (is_array($v)) {
                    $a[$k] = $walk($v, $p);
                } elseif (is_string($v) && !str_starts_with($p, 'table.')) {
                    $a[$k] = str_replace(['<', '>'], ['&lt;', '&gt;'], $v);
                }
            }
            return $a;
        };
        return $walk($this->effectiveConfig(), '');
    }

    private function archiveDir(): string
    {
        return Yii::getAlias($this->archivePath);
    }

    private function archiveRender(string $id, string $text, string $lang, string $version): void
    {
        $dir = $this->archiveDir();
        $hash = substr(sha1($text), 0, 8);
        if (glob("$dir/*-$lang-$id-v$version-$hash.md")) {
            return;
        }
        FileHelper::createDirectory($dir);
        file_put_contents(sprintf('%s/%s-%s-%s-%s-v%s-%s.md', $dir, gmdate('Ymd\THis'), str_replace('.', '', $this->release()), $lang, $id, $version, $hash), $text);
    }

    private function latestArchived(string $id, string $lang, ?string $version = null): ?string
    {
        $tag = $this->document($id, $lang)['lang'] ?? $lang;
        $files = glob($this->archiveDir() . "/*-$tag-$id-v" . ($version ?? '*') . '-*.md') ?: [];
        if ($files === []) {
            return null;
        }
        sort($files);
        return (string)file_get_contents(end($files));
    }

    private function purifierCache(): string
    {
        $dir = Yii::getAlias('@runtime/legal-purifier');
        FileHelper::createDirectory($dir);
        return $dir;
    }
}
