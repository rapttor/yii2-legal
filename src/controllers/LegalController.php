<?php

declare(strict_types=1);

namespace rapttor\yii2legal\controllers;

use rapttor\yii2legal\Email;
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
            if (\rapttor\yii2legal\widgets\AcceptanceCheckbox::posted(array_column($pending, 'id'))) {
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

    public function beforeAction($action): bool
    {
        if ($action->id === 'unsubscribe') {
            $this->enableCsrfValidation = false; // mail clients POST the signed one-click URL without a CSRF token (RFC 8058)
        }
        return parent::beforeAction($action);
    }

    /** The checklist of consents an account must give (required) or may give (optional); shown after sign-in and when something changed. */
    public function actionConsents(string $return = '/'): \yii\web\Response|string
    {
        $legal = $this->legal();
        $user = Yii::$app->user;
        if ($user->isGuest) {
            throw new \yii\web\ForbiddenHttpException('Login required.');
        }
        $this->selectLanguage($legal, null);
        $return = $this->safeReturn((string)(Yii::$app->request->post('return') ?: $return));
        $pending = $legal->consents()->pending($user->id);
        if ($pending === []) {
            return $this->redirect($return);
        }
        $missing = [];
        if (Yii::$app->request->isPost) {
            $res = $legal->consents()->record($user->id, (array)Yii::$app->request->post('consent', []), 'consents');
            if ($res['ok']) {
                return $this->redirect($return);
            }
            $missing = $res['missing'];
        }
        return $this->render('@rapttor/yii2legal/views/legal/consents', ['legal' => $legal, 'pending' => $pending, 'return' => $return, 'missing' => $missing]);
    }

    /** Level 1: stop OPTIONAL email. GET shows a button; POST (also the RFC 8058 one-click from mail clients) acts at once. */
    public function actionUnsubscribe(string $e = '', string $s = ''): string
    {
        $legal = $this->legal();
        $this->selectLanguage($legal, null);
        $email = Email::unb64($e);
        if ($email === '' || !$legal->email()->verify('optional', $email, $s)) {
            Yii::$app->response->statusCode = 400;
            return $this->message($legal, $legal->t('unsub.title'), $legal->t('unsub.invalid'));
        }
        if (Yii::$app->request->isPost) {
            $legal->email()->stopOptional($email, 'one-click');
            return $this->message($legal, $legal->t('unsub.title'), $legal->t('unsub.done', ['{email}' => self::mask($email)]));
        }
        return $this->render('@rapttor/yii2legal/views/legal/unsubscribe', ['legal' => $legal, 'email' => self::mask($email)]);
    }

    /** Level 2, step 1: ask to block ALL email; always answers the same, so addresses cannot be probed. */
    public function actionEmailStop(string $e = '', string $s = ''): string
    {
        $legal = $this->legal();
        $this->selectLanguage($legal, null);
        if (Yii::$app->request->isPost) {
            $legal->email()->requestBlock((string)Yii::$app->request->post('email', ''));
            return $this->message($legal, $legal->t('stop.title', ['{product}' => $legal->productName()]), $legal->t('stop.sent'));
        }
        $email = Email::unb64($e);
        $prefill = $email !== '' && $legal->email()->verify('stop', $email, $s) ? $email : '';
        return $this->render('@rapttor/yii2legal/views/legal/email-stop', ['legal' => $legal, 'prefill' => $prefill]);
    }

    /** Level 2, step 2: the link in the last email. GET only shows a button (mail scanners open links); POST blocks. */
    public function actionEmailConfirm(string $t = ''): string
    {
        $legal = $this->legal();
        $this->selectLanguage($legal, null);
        $title = $legal->t('stop.title', ['{product}' => $legal->productName()]);
        $email = $legal->email()->peek($t);
        if ($email === null) {
            Yii::$app->response->statusCode = 400;
            return $this->message($legal, $title, $legal->t('stop.invalid'));
        }
        if (Yii::$app->request->isPost) {
            $r = $legal->email()->confirmBlock($t);
            return $this->message($legal, $title, $r === 'invalid' ? $legal->t('stop.invalid') : $legal->t('stop.done', ['{email}' => self::mask($email)]));
        }
        return $this->render('@rapttor/yii2legal/views/legal/email-confirm', ['legal' => $legal, 'email' => self::mask($email), 'token' => $t]);
    }

    /** Withdrawal function: form, review/confirm step, acknowledgement. */
    public function actionWithdraw(): string
    {
        $legal = $this->legal();
        $this->selectLanguage($legal, null);
        $req = Yii::$app->request;
        $v = ['name' => trim((string)$req->post('name', '')), 'email' => trim((string)$req->post('email', '')), 'contract' => trim((string)$req->post('contract', ''))];
        $step = (string)$req->post('step', '');
        if ($req->isPost && ($v['email'] === '' || $v['contract'] === '' || !filter_var($v['email'], FILTER_VALIDATE_EMAIL))) {
            $step = '';
            $error = true;
        }
        if ($req->isPost && $step === 'confirm') {
            $r = $legal->withdrawal()->record($v['contract'], $v['email'], $v['name'] ?: null);
            return $this->message($legal, $legal->t('withdraw.title'), $legal->t('withdraw.done', ['{date}' => gmdate('Y-m-d H:i', $r['at']) . ' UTC', '{email}' => $v['email']]));
        }
        return $this->render('@rapttor/yii2legal/views/legal/withdraw', ['legal' => $legal, 'v' => $v, 'review' => $step === 'review', 'error' => $error ?? false]);
    }

    private function message(Legal $legal, string $title, string $text): string
    {
        return $this->render('@rapttor/yii2legal/views/legal/message', ['legal' => $legal, 'title' => $title, 'text' => $text]);
    }

    private static function mask(string $email): string
    {
        [$a, $b] = array_pad(explode('@', $email, 2), 2, '');
        return mb_substr($a, 0, 1) . str_repeat('*', max(1, mb_strlen($a) - 1)) . '@' . $b;
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
