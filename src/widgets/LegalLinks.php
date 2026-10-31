<?php

declare(strict_types=1);

namespace rapttor\yii2legal\widgets;

use rapttor\yii2legal\Legal;
use Yii;
use yii\base\Widget;
use yii\helpers\Html;

/** Footer list of links to the published documents and the app's own legal pages (`Legal::$extraPages`): `<?= LegalLinks::widget() ?>`. */
final class LegalLinks extends Widget
{
    /** @var list<string>|null Document ids to show, in order; null = all published. */
    public ?array $only = null;
    /** Include the app's own pages (`Legal::$extraPages`). With `only`, an extra page is shown when its key is listed. */
    public bool $extras = true;
    /** Language of the links; null = the current one. */
    public ?string $lang = null;
    public array $options = ['class' => 'legal-links'];

    public function run(): string
    {
        /** @var Legal $legal */
        $legal = Yii::$app->get('legal');
        $items = [];
        foreach ($legal->links($this->lang) as $l) {
            if ($l['kind'] === 'extra' ? ($this->extras && ($this->only === null || in_array($l['id'], $this->only, true))) : ($this->only === null || in_array($l['id'], $this->only, true))) {
                $items[$l['id']] = Html::tag('li', Html::a(Html::encode($l['title']), $l['url']));
            }
        }
        if ($this->only !== null) {
            $items = array_replace(array_intersect_key(array_flip($this->only), $items), $items);
        }
        return $items === [] ? '' : Html::tag('ul', implode('', $items), $this->options);
    }
}
