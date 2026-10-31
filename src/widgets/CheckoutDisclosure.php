<?php

declare(strict_types=1);

namespace rapttor\yii2legal\widgets;

use rapttor\yii2legal\Legal;
use Yii;
use yii\base\Widget;
use yii\helpers\Html;

/**
 * The renewal and cancellation terms that must sit NEXT TO the pay button (California Bus. & Prof. Code 17602(a)(1): clear
 * and conspicuous, in visual proximity to the request for consent; EU pre-contract information), with links to the Terms,
 * Refunds and Privacy pages. Put it directly above or around the button.
 *
 *     <?= CheckoutDisclosure::widget(['price' => '€9.99', 'period' => 'month', 'button' => 'Subscribe', 'checkbox' => true, 'cancelUrl' => '/account/billing']) ?>
 *
 * Record the consent with `Yii::$app->legal->acceptance()->record($user->id, ['terms','refunds','privacy'], 'checkout', null, null, $widget->context())`
 * and keep it at least three years.
 */
final class CheckoutDisclosure extends Widget
{
    /** Formatted price with currency and taxes as charged, e.g. "€9.99". */
    public string $price = '';
    /** Billing period as shown to the customer ("month", "year"); null for a one-time payment. */
    public ?string $period = null;
    public ?int $trialDays = null;
    /** Label of the pay button, repeated in the consent sentence. */
    public string $button = '';
    public ?string $cancelUrl = null;
    /** Merchant of record, when a payment provider sells on your behalf. */
    public ?string $provider = null;
    /** @var list<string> */
    public array $docs = ['terms', 'refunds', 'privacy'];
    /** Render an unchecked box that must be ticked (check `CheckoutDisclosure::posted()`). */
    public bool $checkbox = false;
    public string $checkboxName = 'legal_checkout';
    public array $options = ['class' => 'legal-checkout-disclosure'];

    public static function posted(string $name = 'legal_checkout'): bool
    {
        return Yii::$app->request->post($name) === '1';
    }

    /** What was shown, as JSON, to store with the consent record. */
    public function context(): string
    {
        return json_encode(['price' => $this->price, 'period' => $this->period, 'trialDays' => $this->trialDays, 'button' => $this->button, 'provider' => $this->provider, 'docs' => $this->docs, 'lang' => $this->legal()->currentLanguage()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function legal(): Legal
    {
        /** @var Legal */
        return Yii::$app->get('legal');
    }

    public function run(): string
    {
        $legal = $this->legal();
        $lines = [];
        if ($this->period === null) {
            $lines[] = $legal->t('checkout.once', ['{price}' => $this->price]);
        } else {
            if ($this->trialDays) {
                $lines[] = $legal->t('checkout.trial', ['{days}' => (string)$this->trialDays, '{price}' => $this->price, '{period}' => $this->period]);
            } else {
                $lines[] = $legal->t('checkout.price', ['{price}' => $this->price, '{period}' => $this->period]);
            }
            $lines[] = $legal->t('checkout.renews', ['{period}' => $this->period]);
            $lines[] = $legal->t('checkout.cancel', ['{where}' => '']);
        }
        if ($this->provider) {
            $lines[] = $legal->t('checkout.provider', ['{provider}' => $this->provider]);
        }
        $links = [];
        foreach ($this->docs as $id) {
            $d = $legal->document($id);
            if ($d !== null) {
                $links[] = Html::a(Html::encode($d['title']), $legal->url($id), ['target' => '_blank', 'rel' => 'noopener']);
            }
        }
        $sep = Html::encode($legal->t('accept.and'));
        $docs = count($links) > 1 ? implode(', ', array_slice($links, 0, -1)) . $sep . end($links) : ($links[0] ?? '');
        $html = Html::tag('strong', Html::encode($legal->t('checkout.title')));
        foreach ($lines as $l) {
            $html .= Html::tag('p', Html::encode($l));
        }
        if ($this->cancelUrl !== null && $this->period !== null) {
            $html .= Html::tag('p', Html::a(Html::encode($legal->t('checkout.cancel_link')), $this->cancelUrl));
        }
        $sentence = $this->checkbox ? $legal->t('checkout.checkbox') : strtr($legal->t('checkout.consent', ['{button}' => $this->button]), []);
        $sentence = strtr(Html::encode($sentence), ['{docs}' => $docs]);
        $html .= $this->checkbox
            ? Html::tag('p', Html::label(Html::checkbox($this->checkboxName, false, ['value' => '1', 'required' => true]) . ' ' . $sentence))
            : Html::tag('p', $sentence);
        return Html::tag('div', $html, $this->options);
    }
}
