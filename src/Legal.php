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
    /** Extra consent items for the account checklist: [['key' => 'newsletter', 'required' => false, 'version' => '1', 'label' => ['en' => '...', 'sr' => '...']], ...]. */
    public array $consents = [];
    /** Where the account's consents are stored: null = the metadata table (`MetadataConsentStore`); or ['class' => JsonAttributeConsentStore::class, 'modelClass' => User::class, 'attribute' => 'consents'] */
    public array|null $consentStore = null;
    /** Shared per-account metadata table (also usable for theme settings and so on). Consents live in the row `metadataKey` / `metadataRemoteTable` / user id. */
    public string $metadataTable = 'rapttor_metadata';
    public string $metadataKey = 'legal';
    public string $metadataRemoteTable = 'profile';
    public string $suppressionTable = '{{%legal_suppression}}';
    public string $tokenTable = '{{%legal_token}}';
    public string $withdrawalTable = '{{%legal_withdrawal}}';
    public string $requestTable = '{{%legal_request}}';
    /** Internal due dates (days) per request type; the public pages promise no reply time. Privacy rights: one month is the usual legal limit (GDPR Art. 12(3)); check other laws that apply to you. */
    public array $requestDueDays = ['access' => 30, 'export' => 30, 'correct' => 30, 'delete' => 30, 'object' => 30];
    /** Requests accepted per hour from one connection (hashed IP). */
    public int $requestsPerHour = 5;
    /** HMAC key for signed email links; default: the request cookie validation key. Set a long random value in production. */
    public ?string $secret = null;
    /** Sends an email: fn(string $to, string $subject, string $html, string $text, array $headers): void. Default: the Yii `mailer` component. */
    public mixed $mailSender = null;
    /** Mailbox for mail-client unsubscribe (mailto:) in List-Unsubscribe; optional. */
    public ?string $unsubscribeMailto = null;
    /** True when your email provider appends its own unsubscribe link to every message (Postar does): our footer then omits the "stop all email" link instead of showing two. */
    public bool $providerFooter = false;
    /** Where "block all email" requests are also sent, e.g. your email provider's blocklist. Object, config array, or null (local list only). */
    public mixed $blocklist = null;
    /** Event raised after a consumer withdraws from a contract (see WithdrawalEvent): process the refund here. */
    public const EVENT_WITHDRAWAL = 'withdrawal';
    /** Event raised after a privacy or legal request came in (see RequestEvent): alert your admins here. */
    public const EVENT_REQUEST = 'request';

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

    public function consents(): Consents
    {
        return $this->consentsService ??= new Consents($this);
    }

    public function external(): External
    {
        return $this->externalService ??= new External($this);
    }

    public function email(): Email
    {
        return $this->emailService ??= new Email($this);
    }

    public function requests(): Requests
    {
        return $this->requestsService ??= new Requests($this);
    }

    public function withdrawal(): Withdrawal
    {
        return $this->withdrawalService ??= new Withdrawal($this);
    }

    private ?Withdrawal $withdrawalService = null;
    private ?Requests $requestsService = null;
    private ?Consents $consentsService = null;
    private ?External $externalService = null;
    private ?Email $emailService = null;

    /**
     * Likely gaps in the config or the integration. Not errors: things an owner or an AI agent should look at.
     *
     * @return list<string>
     */
    public function hints(): array
    {
        $c = $this->effectiveConfig();
        $f = $c['feature'] ?? [];
        $h = [];
        if (!$this->hasEventHandlers(self::EVENT_REQUEST)) {
            $h[] = 'Privacy and legal requests (/legal/request) are emailed to the privacy/legal mailbox, but nothing in the app alerts your admins: attach a handler to Legal::EVENT_REQUEST (chat, ticket, backoffice badge) and show Yii::$app->legal->requests()->open() in the backoffice.';
        }
        if (!empty($f['gambling'])) {
            $h[] = 'feature.gambling is on: the tick box is not real age verification. Check licences for every country you serve and use verified age and identity checks where the law requires them.';
        }
        if (array_key_exists('age_gate', $f) && !$f['age_gate']) {
            $h[] = 'feature.age_gate is switched off: the age and legal-age confirmation is on by default and is asked of every account; keep it off only with the owner\'s explicit approval.';
        }
        if (array_key_exists('uploads', $f) && !$f['uploads']) {
            $h[] = 'feature.uploads is switched off: the upload and copyright responsibility confirmation is on by default; keep it off only if the app takes no user files or content.';
        }
        if (!empty($f['anonymous']) && empty($f['age_gate'])) {
            $h[] = 'feature.anonymous is on but feature.age_gate is off: anonymous accounts need the age and legal-age confirmation (set feature.age_gate, policy.age_gate and policy.age_topics).';
        }
        if (!empty($f['age_gate']) && empty($c['policy']['age_topics'])) {
            $h[] = 'feature.age_gate is on but policy.age_topics is empty: say what the confirmation is for (for example "chat and gambling-style games").';
        }
        if (!empty($c['data']['external']) && empty($f['external'])) {
            $h[] = 'data.external lists third-party resources but feature.external is off: the Privacy and Cookie Policies will not mention them.';
        }
        if (!empty($f['external']) && empty($c['data']['external'])) {
            $h[] = 'feature.external is on but data.external is empty: list every resource that receives the visitor\'s IP address (fonts, maps, video, captcha).';
        }
        if (!empty($f['merchant_of_record']) && empty($c['payments']['provider'])) {
            $h[] = 'feature.merchant_of_record is on but payments.provider is empty.';
        }
        if ((!empty($f['ugc']) || !empty($f['uploads'])) && empty($c['company']['dmca_agent'])) {
            $h[] = 'The app stores user content: register a DMCA designated agent (dmca.copyright.gov) and set company.dmca_agent and company.dmca_number.';
        }
        $store = $this->consentStore;
        if (is_array($store) && is_a((string)($store['class'] ?? ''), stores\JsonAttributeConsentStore::class, true) && !empty($store['modelClass'])) {
            try {
                $col = $store['attribute'] ?? 'consents';
                if (!isset(($store['modelClass'])::getTableSchema()->columns[$col])) {
                    $h[] = "consentStore keeps consents in the '$col' attribute of {$store['modelClass']}, but its table has no such column: add a TEXT or JSON column named $col.";
                }
            } catch (\Throwable) {
                // model not loadable here (console without the app models): nothing to say
            }
        }
        try {
            $agents = Yii::getAlias('@app/AGENTS.md');
            if (!is_file($agents) || !str_contains((string)file_get_contents($agents), 'rapttor-legal:begin')) {
                $h[] = 'AGENTS.md has no legal checklist block: run `php yii legal/agents --llms` so AI coding agents get the rules for this app (age confirmation, consents, uploads, email, payments).';
            }
        } catch (\Throwable) {
        }
        return $h;
    }

    /** Key for the keyed hashes of IP address and browser details (default: `secret`, else the request cookie validation key). */
    public function hashKey(): string
    {
        $k = $this->secret;
        if ($k === null && Yii::$app instanceof \yii\web\Application) {
            $k = (string)Yii::$app->request->cookieValidationKey;
        }
        return $k !== null && $k !== '' ? $k : 'rapttor-legal';
    }

    /** Keyed hash (HMAC-SHA256). Not reversible without the key; same input gives the same hash, so it can be compared later. */
    public function hash(?string $value): ?string
    {
        return $value === null || $value === '' ? null : hash_hmac('sha256', $value, $this->hashKey());
    }

    /**
     * What to keep as proof that a person saw and accepted a specific thing. Only hashes of technical data, never the raw values.
     *
     * @param string $shownText the exact wording next to the checkbox
     * @param string|null $documentText the rendered document that was accepted (Markdown), if the item is a document
     * @return array<string,string|null> ip, ua (hashed IP and browser details), text and doc (SHA-256 of what was shown)
     */
    public function evidence(string $shownText, ?string $documentText = null): array
    {
        $web = Yii::$app instanceof \yii\web\Application;
        $req = $web ? Yii::$app->request : null;
        return [
            'ip' => $this->hash($req?->getUserIP()),
            'ua' => $this->hash($req === null ? null : ($req->getUserAgent() . '|' . $req->headers->get('Accept-Language')) ),
            'text' => hash('sha256', $shownText),
            'doc' => $documentText === null ? null : hash('sha256', $documentText),
        ];
    }

    /** The name users know the service by: config product.name, else the company name. */
    public function productName(): string
    {
        $c = $this->effectiveConfig();
        return (string)(($c['product']['name'] ?? '') ?: ($c['company']['name'] ?? ''));
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
    /** @var array<string,array<string,string>> */
    private static array $messages = [];

    /** UI string in a legal-page language ("sr-Cyrl" is transliterated from "sr"); English if missing. @param array<string,string> $params */
    public function t(string $key, array $params = [], ?string $lang = null): string
    {
        $lang ??= $this->currentLanguage();
        $base = $this->library()->normalizeLanguage($lang) ?? 'en';
        $cyr = $base === 'sr-Cyrl';
        $base = $cyr ? 'sr' : $base;
        foreach (['en', $base] as $l) {
            if (!isset(self::$messages[$l])) {
                $file = __DIR__ . "/messages/$l.php";
                self::$messages[$l] = is_file($file) ? require $file : [];
            }
        }
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
        // Default-on consents (age and legal-age confirmation, upload responsibility) apply unless the site sets them to false.
        return $this->effective = $this->library()->withDefaults(array_replace_recursive($file, $this->config));
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
