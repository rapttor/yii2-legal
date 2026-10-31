<?php

declare(strict_types=1);

namespace rapttor\yii2legal\widgets;

use rapttor\yii2legal\Legal;
use Yii;
use yii\base\Widget;
use yii\helpers\Html;

/**
 * "I accept the Terms and the Privacy Policy" checkbox for sign-up and checkout forms. In the action that handles the form
 * check `Acceptance::fromRequest()` and call `Yii::$app->legal->acceptance()->record($user->id)`.
 */
final class AcceptanceCheckbox extends Widget
{
    /** @var list<string>|null document ids; null = the component's `acceptDocs` */
    public ?array $docs = null;
    public string $name = 'legal_accept';
    public array $options = ['class' => 'legal-accept'];

    /**
     * True only when EVERY listed document has its own ticked box. One shared box for several documents does not count:
     * a person must be able to refuse or later dispute each one separately.
     *
     * @param list<string>|null $docs default: the component's `acceptDocs`
     */
    public static function posted(?array $docs = null, string $name = 'legal_accept'): bool
    {
        $docs ??= Yii::$app->get('legal')->acceptDocs;
        $got = Yii::$app->request->post($name);
        if (!is_array($got) || $docs === []) {
            return false;
        }
        foreach ($docs as $id) {
            if (($got[$id] ?? null) !== '1') {
                return false;
            }
        }
        return true;
    }

    /** One unticked, required checkbox per document, each with its own link and version. */
    public function run(): string
    {
        /** @var Legal $legal */
        $legal = Yii::$app->get('legal');
        $rows = [];
        foreach ($this->docs ?? $legal->acceptDocs as $id) {
            $d = $legal->document($id);
            if ($d === null) {
                continue;
            }
            $link = Html::a(Html::encode($d['title']), $legal->url($id), ['target' => '_blank', 'rel' => 'noopener']);
            $label = strtr(Html::encode($legal->t('accept.label')), ['{docs}' => $link]) . ' <small>(' . Html::encode($d['version']) . ')</small>';
            $rows[] = Html::tag('div', Html::label(Html::checkbox($this->name . '[' . $id . ']', false, ['value' => '1', 'required' => true]) . ' ' . $label), ['class' => 'legal-accept-item']);
        }
        return $rows === [] ? '' : Html::tag('div', implode("\n", $rows), $this->options);
    }
}
