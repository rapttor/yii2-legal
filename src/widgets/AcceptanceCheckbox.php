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

    public static function posted(string $name = 'legal_accept'): bool
    {
        return Yii::$app->request->post($name) === '1';
    }

    public function run(): string
    {
        /** @var Legal $legal */
        $legal = Yii::$app->get('legal');
        $links = [];
        foreach ($this->docs ?? $legal->acceptDocs as $id) {
            $d = $legal->document($id);
            if ($d !== null) {
                $links[] = Html::a(Html::encode($d['title']), $legal->url($id), ['target' => '_blank', 'rel' => 'noopener']);
            }
        }
        if ($links === []) {
            return '';
        }
        $sep = Html::encode($legal->t('accept.and'));
        $list = count($links) > 1 ? implode(', ', array_slice($links, 0, -1)) . $sep . end($links) : $links[0];
        $label = strtr(Html::encode($legal->t('accept.label')), ['{docs}' => $list]);
        return Html::tag('div', Html::label(Html::checkbox($this->name, false, ['value' => '1', 'required' => true]) . ' ' . $label), $this->options);
    }
}
