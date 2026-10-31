<?php

declare(strict_types=1);

namespace rapttor\yii2legal;

use rapttor\yii2legal\stores\ConsentStore;
use rapttor\yii2legal\stores\MetadataConsentStore;
use Yii;

/**
 * The consents an account must give, remembered with the account and asked again when they change.
 *
 * Items are derived from the site's features. ON BY DEFAULT: the documents in `acceptDocs`, the age and legal-age confirmation
 * (`feature.age_gate`) and the upload/copyright responsibility (`feature.uploads`), all required; set a flag to false to drop it.
 * Opt-in: gambling (`feature.gambling`, own age and lawfulness confirmation), product emails
 * (`feature.marketing`, optional), every third-party resource in `data.external` (optional), plus `Legal::$consents`.
 * Each item has a version; a stored answer for another version counts as missing. Required items block use until given;
 * optional ones are asked once per version and may be declined.
 */
final class Consents
{
    private ?ConsentStore $store = null;

    public function __construct(private Legal $legal)
    {
    }

    public function store(): ConsentStore
    {
        if ($this->store === null) {
            $cfg = $this->legal->consentStore;
            $this->store = $cfg === null ? new MetadataConsentStore($this->legal) : Yii::createObject($cfg);
        }
        return $this->store;
    }

    /** @return list<array{key:string,version:string,required:bool,kind:string,label:string,hint:?string,url:?string}> */
    public function definitions(?string $lang = null): array
    {
        $lang ??= $this->legal->currentLanguage();
        $cfg = $this->legal->effectiveConfig();
        $f = $cfg['feature'] ?? [];
        $out = [];
        foreach ($this->legal->acceptDocs as $id) {
            $d = $this->legal->document($id, $lang);
            if ($d !== null) {
                $out[] = [
                    'key' => $id, 'version' => self::minor($d['version']), 'required' => true, 'kind' => 'document',
                    'label' => $this->legal->t('consent.accept_doc', ['{doc}' => $d['title']], $lang), 'hint' => null, 'url' => $this->legal->url($id, $d['lang']),
                ];
            }
        }
        if (!empty($f['age_gate'])) {
            $level = (string)($cfg['policy']['age_gate'] ?? '18');
            $topics = trim((string)($cfg['policy']['age_topics'] ?? ''));
            $out[] = ['key' => 'age', 'version' => $level, 'required' => true, 'kind' => 'age', 'url' => null,
                'label' => $this->legal->t('consent.age', ['{level}' => $level], $lang),
                'hint' => $topics !== '' ? $this->legal->t('consent.age_hint', ['{topics}' => $topics], $lang) : $this->legal->t('consent.age_generic', [], $lang)];
        }
        if (!empty($f['gambling'])) {
            $lvl = (string)($cfg['policy']['gambling_age'] ?? '18');
            $out[] = ['key' => 'gambling', 'version' => $lvl, 'required' => true, 'kind' => 'age', 'url' => null,
                'label' => $this->legal->t('consent.gambling', ['{level}' => $lvl], $lang), 'hint' => $this->legal->t('consent.gambling_hint', [], $lang)];
        }
        if (!empty($f['uploads'])) {
            $out[] = ['key' => 'uploads', 'version' => '1', 'required' => true, 'kind' => 'confirm', 'url' => null,
                'label' => $this->legal->t('consent.uploads', [], $lang), 'hint' => $this->legal->t('consent.uploads_hint', [], $lang)];
        }
        $regions = trim((string)($cfg['policy']['excluded_regions'] ?? ''));
        if ($regions !== '') { // we cannot see where a visitor really is (VPNs): the visitor confirms it. A changed list asks everyone again.
            $out[] = ['key' => 'regions', 'version' => substr(md5(mb_strtolower($regions)), 0, 8), 'required' => true, 'kind' => 'confirm', 'url' => null,
                'label' => $this->legal->t('consent.regions', [], $lang), 'hint' => $this->legal->t('consent.regions_hint', ['{countries}' => $this->regionNames($cfg, $lang)], $lang)];
        }
        if (!empty($f['marketing'])) {
            $out[] = ['key' => 'marketing', 'version' => '1', 'required' => false, 'kind' => 'optional', 'url' => null,
                'label' => $this->legal->t('consent.marketing', [], $lang), 'hint' => $this->legal->t('consent.marketing_hint', [], $lang)];
        }
        foreach ($this->legal->external()->items() as $it) {
            $out[] = ['key' => 'ext_' . $it['key'], 'version' => '1', 'required' => false, 'kind' => 'external', 'url' => null,
                'label' => $this->legal->t('consent.external', ['{name}' => $it['name']], $lang),
                'hint' => $this->legal->t('consent.external_hint', ['{provider}' => $it['provider'], '{country}' => $it['country']], $lang)];
        }
        foreach ($this->legal->consents as $c) {
            $label = is_array($c['label'] ?? null) ? ($c['label'][$lang] ?? $c['label']['en'] ?? reset($c['label'])) : (string)($c['label'] ?? $c['key']);
            $out[] = ['key' => (string)$c['key'], 'version' => (string)($c['version'] ?? '1'), 'required' => !empty($c['required']), 'kind' => 'custom', 'url' => $c['url'] ?? null, 'label' => (string)$label,
                'hint' => isset($c['hint']) ? (string)(is_array($c['hint']) ? ($c['hint'][$lang] ?? $c['hint']['en'] ?? reset($c['hint'])) : $c['hint']) : null];
        }
        return $out;
    }

    /** @return array<string,array<string,mixed>> */
    public function state(int|string $userId): array
    {
        return $this->store()->load((string)$userId);
    }

    /** Items the account has not answered for their current version. @return list<array<string,mixed>> */
    public function pending(int|string $userId, ?string $lang = null): array
    {
        $state = $this->state($userId);
        return array_values(array_filter($this->definitions($lang), static fn(array $d): bool => ($state[$d['key']]['v'] ?? null) !== $d['version']));
    }

    /** Required items that were not granted in $answers: for a form shown before the account exists (sign-up). @return list<string> keys */
    public function missingRequired(array $answers): array
    {
        return array_values(array_map(static fn(array $d): string => $d['key'], array_filter(
            $this->definitions(),
            static fn(array $d): bool => $d['required'] && empty($answers[$d['key']])
        )));
    }

    /** Pending items that must be granted before the app may be used. @return list<array<string,mixed>> */
    public function blocking(int|string $userId, ?string $lang = null): array
    {
        return array_values(array_filter($this->pending($userId, $lang), static fn(array $d): bool => $d['required']));
    }

    /** True if the account granted this item in its current version. */
    public function granted(int|string $userId, string $key): bool
    {
        $s = $this->state($userId)[$key] ?? null;
        foreach ($this->definitions() as $d) {
            if ($d['key'] === $key) {
                return $s !== null && $s['v'] === $d['version'] && $s['granted'];
            }
        }
        return false;
    }

    /**
     * Store the answers for all pending items. Nothing is stored when a required item is not granted.
     *
     * @param array<string,mixed> $answers key => truthy/falsy
     * @return array{ok:bool,missing:list<string>}
     */
    public function record(int|string $userId, array $answers, string $source = 'consents', ?string $lang = null): array
    {
        $lang ??= $this->legal->currentLanguage();
        $pending = $this->pending($userId, $lang);
        $missing = [];
        foreach ($pending as $d) {
            if ($d['required'] && empty($answers[$d['key']])) {
                $missing[] = $d['key'];
            }
        }
        if ($missing !== []) {
            return ['ok' => false, 'missing' => $missing];
        }
        $state = $this->state($userId);
        $cookie = [];
        foreach ($pending as $d) {
            $granted = !empty($answers[$d['key']]);
            $docText = $d['kind'] === 'document' ? $this->documentText($d['key'], $lang) : null;
            $entry = ['v' => $d['version'], 'granted' => $granted, 'at' => time(), 'lang' => $lang, 'source' => $source, 'ev' => $this->legal->evidence($d['label'] . (($d['hint'] ?? '') !== '' ? "\n" . $d['hint'] : ''), $docText)];
            $state[$d['key']] = $entry;
            if ($d['kind'] === 'external') {
                $cookie[$d['key']] = $granted;
            }
        }
        $this->store()->save((string)$userId, $state);
        $this->logDocuments($userId, $pending, $answers, $lang, $state, $source);
        if ($cookie !== [] && Yii::$app instanceof \yii\web\Application) {
            $this->legal->consent()->merge($cookie); // the browser follows the account's choice for third-party resources
        }
        return ['ok' => true, 'missing' => []];
    }

    /** The excluded countries in the visitor's language (ICU names when the intl extension is there), then any names the owner typed by hand. */
    private function regionNames(array $cfg, string $lang): string
    {
        $map = (array)($cfg['policy']['excluded_map'] ?? []);
        $display = in_array($lang, ['sh', 'sr', 'hr', 'cnr'], true) ? 'bs' : $lang;
        $names = [];
        foreach ($map as $code => $english) {
            $local = class_exists(\Locale::class) ? \Locale::getDisplayRegion('und-' . $code, $display) : '';
            $names[] = $local !== '' && $local !== $code && !str_starts_with($local, 'Unknown') ? (string)$local : (string)$english;
        }
        foreach (explode(',', (string)($cfg['policy']['excluded_regions'] ?? '')) as $n) {
            $n = trim($n);
            if ($n !== '' && !in_array($n, $map, true)) {
                $names[] = $n; // the owner's own wording
            }
        }
        return implode(', ', array_unique($names));
    }

    /** The rendered Markdown of the document version being accepted (its SHA-256 goes into the evidence). */
    private function documentText(string $id, string $lang): ?string
    {
        try {
            return $this->legal->markdown($id, $lang);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Documents accepted here also go to the acceptance log (version, language, time, hashed IP) when its table exists. */
    private function logDocuments(int|string $userId, array $pending, array $answers, string $lang, array $state = [], string $source = 'consents'): void
    {
        $docs = array_values(array_map(static fn(array $d): string => $d['key'], array_filter($pending, static fn(array $d): bool => $d['kind'] === 'document' && !empty($answers[$d['key']]))));
        if ($docs === [] || !is_numeric($userId)) {
            return;
        }
        try {
            $ctx = json_encode(['lang' => $lang, 'evidence' => array_map(fn(string $id): array => $state[$id]['ev'] ?? [], array_combine($docs, $docs))], JSON_THROW_ON_ERROR);
            $this->legal->acceptance()->record((int)$userId, $docs, $source, null, $lang, $ctx);
        } catch (\Throwable $e) {
            Yii::warning('Acceptance log not written: ' . $e->getMessage(), __METHOD__);
        }
    }

    /** "1.1.3" => "1.1": a wording fix (PATCH) does not ask again. */
    public static function minor(string $version): string
    {
        return implode('.', array_slice(explode('.', $version . '.0'), 0, 2));
    }
}
