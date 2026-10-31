<?php

declare(strict_types=1);

namespace rapttor\yii2legal;

/**
 * Third-party resources that receive the visitor's IP address when loaded (fonts, maps, video, captcha, chat...).
 * Declare each one in the legal config under `data.external`:
 *
 *     'data' => ['external' => [['key' => 'google_fonts', 'name' => 'Google Fonts', 'provider' => 'Google', 'purpose' => 'fonts', 'country' => 'USA']]]
 *
 * and load it only if `Yii::$app->legal->external()->allows('google_fonts')`. Prefer self-hosting, which needs no consent.
 */
final class External
{
    public function __construct(private Legal $legal)
    {
    }

    /** @return list<array{key:string,name:string,provider:string,purpose:string,country:string}> */
    public function items(): array
    {
        $out = [];
        foreach ($this->legal->effectiveConfig()['data']['external'] ?? [] as $r) {
            if (!empty($r['key'])) {
                $out[] = ['key' => (string)$r['key'], 'name' => (string)($r['name'] ?? $r['key']), 'provider' => (string)($r['provider'] ?? ''), 'purpose' => (string)($r['purpose'] ?? ''), 'country' => (string)($r['country'] ?? '')];
            }
        }
        return $out;
    }

    /** True only if the visitor agreed to this resource (cookie settings or account consents). */
    public function allows(string $key): bool
    {
        return $this->legal->consent()->allows('ext_' . $key);
    }
}
