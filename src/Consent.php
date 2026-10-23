<?php

declare(strict_types=1);

namespace rapttor\yii2legal;

use Yii;
use yii\web\Cookie;

/**
 * Visitor's cookie choices. Nothing is stored before the visitor acts; Global Privacy Control (header Sec-GPC: 1) counts as
 * a refusal of every non-essential category and the banner is not shown. Categories exist only for features the site has:
 * `analytics` (feature.analytics) and `advertising` (feature.ads).
 *
 *     if (Yii::$app->legal->consent()->allows('analytics')) { ... load analytics ... }
 */
final class Consent
{
    public function __construct(private Legal $legal)
    {
    }

    /** @return list<string> */
    public function categories(): array
    {
        $f = $this->legal->effectiveConfig()['feature'] ?? [];
        $out = [];
        if (!empty($f['analytics'])) {
            $out[] = 'analytics';
        }
        if (!empty($f['ads'])) {
            $out[] = 'advertising';
        }
        return $out;
    }

    public function gpc(): bool
    {
        return Yii::$app instanceof \yii\web\Application && Yii::$app->request->headers->get('Sec-GPC') === '1';
    }

    /** @return array<string,bool>|null the stored decision, null if the visitor has not decided */
    public function decision(): ?array
    {
        if (!(Yii::$app instanceof \yii\web\Application)) {
            return null;
        }
        $raw = Yii::$app->request->cookies->getValue($this->legal->consentCookie);
        $d = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($d) || ($d['v'] ?? 0) !== 1 || !isset($d['c']) || !is_array($d['c'])) {
            return null;
        }
        $out = [];
        foreach ($this->categories() as $cat) {
            $out[$cat] = !empty($d['c'][$cat]);
        }
        return $out;
    }

    public function allows(string $category): bool
    {
        if ($this->gpc() || !in_array($category, $this->categories(), true)) {
            return false;
        }
        return ($this->decision()[$category] ?? false) === true;
    }

    /** True when the banner should be shown: the site uses non-essential cookies, GPC is off and no decision is stored. */
    public function needsBanner(): bool
    {
        return $this->categories() !== [] && !$this->gpc() && $this->decision() === null;
    }

    /** Store the decision in a cookie (call only in response to a visitor action). @param array<string,bool> $choices */
    public function save(array $choices): void
    {
        $c = [];
        foreach ($this->categories() as $cat) {
            $c[$cat] = !empty($choices[$cat]);
        }
        Yii::$app->response->cookies->add(new Cookie([
            'name' => $this->legal->consentCookie,
            'value' => json_encode(['v' => 1, 'c' => $c, 't' => time()], JSON_THROW_ON_ERROR),
            'expire' => time() + 180 * 86400,
            'httpOnly' => true,
            'secure' => Yii::$app->request->isSecureConnection,
            'sameSite' => Cookie::SAME_SITE_LAX,
        ]));
    }
}
