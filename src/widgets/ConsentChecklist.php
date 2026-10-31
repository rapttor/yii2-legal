<?php

declare(strict_types=1);

namespace rapttor\yii2legal\widgets;

use rapttor\yii2legal\Legal;
use Yii;
use yii\base\Widget;
use yii\helpers\Html;

/**
 * The consents as a list of separate, unticked checkboxes (`consent[<key>]`): put it in the sign-in form of a NEW account so the
 * person confirms each item before the account exists, and on the consents page for the items still open. Check the post with
 * `Yii::$app->legal->consents()->missingRequired($_POST['consent'])` and store it with `consents()->record($user->id, $answers, 'signup')`.
 */
final class ConsentChecklist extends Widget
{
    /** @var list<array<string,mixed>>|null definitions to show; null = all of `Consents::definitions()` */
    public ?array $items = null;
    /** @var list<string> keys to mark as not confirmed */
    public array $missing = [];
    public string $name = 'consent';

    public function run(): string
    {
        /** @var Legal $legal */
        $legal = Yii::$app->get('legal');
        $out = '';
        foreach ($this->items ?? $legal->consents()->definitions() as $d) {
            $link = $d['url'] ? ' ' . Html::a(Html::encode($legal->t('consents.read')), $d['url'], ['target' => '_blank', 'rel' => 'noopener', 'style' => 'font-size:.875em']) : '';
            $label = Html::checkbox($this->name . '[' . $d['key'] . ']', false, ['value' => '1', 'style' => 'margin-right:.4em']) . Html::encode($d['label'])
                . ' <small style="opacity:.7">(' . Html::encode($legal->t($d['required'] ? 'consents.required' : 'consents.optional')) . ')</small>';
            $hint = ($d['hint'] ?? '') !== '' ? Html::tag('small', Html::encode($d['hint']), ['class' => 'legal-consent-hint', 'style' => 'display:block;opacity:.7;margin:.15rem 0 0 1.6em']) : '';
            $out .= Html::tag('p', Html::tag('label', $label) . $link . $hint, ['class' => 'legal-consent-item' . (in_array($d['key'], $this->missing, true) ? ' legal-consent-missing' : '')]);
        }
        $lang = $legal->currentLanguage();
        return Html::tag('div', $out, ['class' => 'legal-consent-list', 'lang' => $lang, 'dir' => $legal->library()->direction($lang)]);
    }
}
