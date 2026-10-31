<?php

declare(strict_types=1);

namespace rapttor\yii2legal;

use rapttor\yii2legal\stores\ConsentStore;
use rapttor\yii2legal\stores\TableConsentStore;
use Yii;

/**
 * The consents an account must give, remembered with the account and asked again when they change.
 *
 * Items are derived from the site's features: the documents in `acceptDocs` (required), the age confirmation
 * (`feature.age_gate`, required), the upload/copyright responsibility (`feature.uploads`, required), product emails
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
            $this->store = $cfg === null ? new TableConsentStore($this->legal) : Yii::createObject($cfg);
        }
        return $this->store;
    }

    /** @return list<array{key:string,version:string,required:bool,kind:string,label:string,url:?string}> */
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
                    'label' => $this->legal->t('consent.accept_doc', ['{doc}' => $d['title']], $lang), 'url' => $this->legal->url($id, $d['lang']),
                ];
            }
        }
        if (!empty($f['age_gate'])) {
            $out[] = ['key' => 'age', 'version' => (string)($cfg['policy']['age_gate'] ?? '18'), 'required' => true, 'kind' => 'age', 'url' => null,
                'label' => $this->legal->t('consent.age', ['{level}' => (string)($cfg['policy']['age_gate'] ?? '18'), '{topics}' => (string)(($cfg['policy']['age_topics'] ?? '') ?: $this->legal->t('consent.age_default_topics', [], $lang))], $lang)];
        }
        if (!empty($f['uploads'])) {
            $out[] = ['key' => 'uploads', 'version' => '1', 'required' => true, 'kind' => 'confirm', 'url' => null, 'label' => $this->legal->t('consent.uploads', [], $lang)];
        }
        if (!empty($f['marketing'])) {
            $out[] = ['key' => 'marketing', 'version' => '1', 'required' => false, 'kind' => 'optional', 'url' => null, 'label' => $this->legal->t('consent.marketing', [], $lang)];
        }
        foreach ($this->legal->external()->items() as $it) {
            $out[] = ['key' => 'ext_' . $it['key'], 'version' => '1', 'required' => false, 'kind' => 'external', 'url' => null,
                'label' => $this->legal->t('consent.external', ['{name}' => $it['name'], '{provider}' => $it['provider'], '{country}' => $it['country']], $lang)];
        }
        foreach ($this->legal->consents as $c) {
            $label = is_array($c['label'] ?? null) ? ($c['label'][$lang] ?? $c['label']['en'] ?? reset($c['label'])) : (string)($c['label'] ?? $c['key']);
            $out[] = ['key' => (string)$c['key'], 'version' => (string)($c['version'] ?? '1'), 'required' => !empty($c['required']), 'kind' => 'custom', 'url' => $c['url'] ?? null, 'label' => (string)$label];
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
            $state[$d['key']] = ['v' => $d['version'], 'granted' => $granted, 'at' => time(), 'lang' => $lang, 'source' => $source];
            if ($d['kind'] === 'external') {
                $cookie[$d['key']] = $granted;
            }
        }
        $this->store()->save((string)$userId, $state);
        if ($cookie !== [] && Yii::$app instanceof \yii\web\Application) {
            $this->legal->consent()->merge($cookie); // the browser follows the account's choice for third-party resources
        }
        return ['ok' => true, 'missing' => []];
    }

    /** "1.1.3" => "1.1": a wording fix (PATCH) does not ask again. */
    public static function minor(string $version): string
    {
        return implode('.', array_slice(explode('.', $version . '.0'), 0, 2));
    }
}
