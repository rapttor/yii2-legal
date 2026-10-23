<?php

declare(strict_types=1);

namespace rapttor\yii2legal\widgets;

use rapttor\yii2legal\Legal;
use Yii;
use yii\base\Widget;
use yii\helpers\Html;

/** Footer list of links to the published documents: `<?= LegalLinks::widget() ?>`. */
final class LegalLinks extends Widget
{
    /** @var list<string>|null Document ids to show, in order; null = all published. */
    public ?array $only = null;
    /** Language of the links; null = the current one. */
    public ?string $lang = null;
    public array $options = ['class' => 'legal-links'];

    public function run(): string
    {
        /** @var Legal $legal */
        $legal = Yii::$app->get('legal');
        $items = [];
        foreach ($legal->documents($this->lang) as $d) {
            if ($this->only === null || in_array($d['id'], $this->only, true)) {
                $items[$d['id']] = Html::tag('li', Html::a(Html::encode($d['title']), $legal->url($d['id'], $this->lang)));
            }
        }
        if ($this->only !== null) {
            $items = array_replace(array_intersect_key(array_flip($this->only), $items), $items);
        }
        return $items === [] ? '' : Html::tag('ul', implode('', $items), $this->options);
    }
}
