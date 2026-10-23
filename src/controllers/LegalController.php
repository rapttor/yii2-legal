<?php

declare(strict_types=1);

namespace rapttor\yii2legal\controllers;

use rapttor\yii2legal\Legal;
use Yii;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

/** Serves the published legal documents in the language named in the URL (or the site's default). Uses the application's layout. */
final class LegalController extends Controller
{
    public function actionIndex(?string $lang = null): string
    {
        $legal = $this->legal();
        $tag = $this->selectLanguage($legal, $lang);
        Yii::$app->response->headers->set('Content-Language', $tag);
        return $this->render('@rapttor/yii2legal/views/legal/index', ['legal' => $legal, 'lang' => $tag]);
    }

    public function actionView(string $doc, ?string $lang = null): string
    {
        $legal = $this->legal();
        $tag = $this->selectLanguage($legal, $lang);
        $id = Legal::idFromSlug($doc);
        $entry = $legal->document($id, $tag);
        if ($entry === null) {
            throw new NotFoundHttpException('Document not found.');
        }
        $html = $legal->html($id, $tag);

        $this->view->title = $entry['title'];
        $this->view->registerMetaTag(['name' => 'description', 'content' => $entry['title'] . ' - ' . ($legal->effectiveConfig()['company']['name'] ?? '')]);
        // Alternate language versions (only languages in which this document really exists and the site offers).
        foreach ($legal->offered() as $other) {
            $e = $legal->document($id, $other);
            if ($e !== null && !$e['fallback']) {
                $this->view->registerLinkTag(['rel' => 'alternate', 'hreflang' => $other, 'href' => $legal->url($id, $other, true)], "hreflang-$other");
            }
        }
        $this->view->registerLinkTag(['rel' => 'alternate', 'hreflang' => 'x-default', 'href' => $legal->url($id, 'en', true)], 'hreflang-x-default');
        Yii::$app->response->headers->set('Content-Language', $entry['lang']);

        return $this->render('@rapttor/yii2legal/views/legal/view', [
            'legal' => $legal, 'entry' => $entry, 'html' => $html, 'lang' => $tag,
            'notice' => $entry['fallback'] ? strtr($legal->fallbackNotice, [
                '{requested}' => $legal->library()->languageName($entry['requested']),
                '{actual}' => $legal->library()->languageName($entry['lang']),
            ]) : null,
            'dir' => $legal->library()->direction($entry['lang']),
            'toc' => $this->toc($html),
        ]);
    }

    /** Acceptance page: shown to logged-in users who must accept a new version of a document. */
    public function actionAccept(string $return = '/'): \yii\web\Response|string
    {
        $legal = $this->legal();
        $user = Yii::$app->user;
        if ($user->isGuest) {
            throw new \yii\web\ForbiddenHttpException('Login required.');
        }
        $this->selectLanguage($legal, null);
        $return = $this->safeReturn((string)(Yii::$app->request->post('return') ?: $return));
        $pending = $legal->acceptance()->pending((int)$user->id);
        if ($pending === []) {
            return $this->redirect($return);
        }
        $error = false;
        if (Yii::$app->request->isPost) {
            if (\rapttor\yii2legal\widgets\AcceptanceCheckbox::posted()) {
                $legal->acceptance()->record((int)$user->id, array_column($pending, 'id'), 'reaccept');
                return $this->redirect($return);
            }
            $error = true;
        }
        return $this->render('@rapttor/yii2legal/views/legal/accept', ['legal' => $legal, 'pending' => $pending, 'return' => $return, 'error' => $error]);
    }

    /** Cookie settings page and the endpoint of the banner buttons. */
    public function actionConsent(string $return = '/'): \yii\web\Response|string
    {
        $legal = $this->legal();
        $this->selectLanguage($legal, null);
        $return = $this->safeReturn((string)(Yii::$app->request->post('return') ?: $return));
        if (Yii::$app->request->isPost) {
            $consent = $legal->consent();
            $action = (string)Yii::$app->request->post('action');
            $all = array_fill_keys($consent->categories(), true);
            $choices = match ($action) {
                'accept_all' => $all,
                'save' => array_map(static fn() => true, (array)Yii::$app->request->post('c', [])),
                default => [], // reject_all and anything unknown: nothing non-essential
            };
            $consent->save($choices);
            return $this->redirect($return);
        }
        return $this->render('@rapttor/yii2legal/views/legal/consent', ['legal' => $legal, 'return' => $return]);
    }

    /** Table of contents from the h2 headings (only for longer documents). @return list<array{id:string,title:string}> */
    private function toc(string $html): array
    {
        preg_match_all('/<h2 id="([a-z0-9-]+)">(.*?)<\/h2>/s', $html, $m, PREG_SET_ORDER);
        $items = array_map(static fn(array $x): array => ['id' => $x[1], 'title' => trim(strip_tags($x[2]))], $m);
        return count($items) >= 5 ? $items : [];
    }

    /** Only same-site relative URLs may be returned to (no open redirect). */
    private function safeReturn(string $url): string
    {
        return str_starts_with($url, '/') && !str_starts_with($url, '//') && !str_contains($url, '\\') ? $url : '/';
    }

    /** Language from the URL if the site offers it, else the site's default; also makes it the language of the whole request. */
    private function selectLanguage(Legal $legal, ?string $lang): string
    {
        if ($lang !== null) {
            $tag = $legal->library()->normalizeLanguage($lang);
            if ($tag === null || !in_array($tag, $legal->offered(), true)) {
                throw new NotFoundHttpException('Language not available.');
            }
        } else {
            $tag = $legal->defaultUrlLanguage();
        }
        $legal->forceLanguage($tag);
        return $tag;
    }

    private function legal(): Legal
    {
        /** @var Legal */
        return Yii::$app->get('legal');
    }
}
