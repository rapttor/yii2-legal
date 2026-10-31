<?php

declare(strict_types=1);

namespace rapttor\yii2legal;

use rapttor\yii2legal\blocklist\BlocklistInterface;
use rapttor\yii2legal\blocklist\NullBlocklist;
use Yii;
use yii\db\Query;
use yii\helpers\Html;
use yii\helpers\Url;

/**
 * Stopping emails, in two levels:
 *
 *  1. OPTIONAL mail (news, tips): one click, effective at once, no confirmation (CAN-SPAM allows nothing more than visiting
 *     one page; Gmail and Yahoo expect RFC 8058 one-click). Use unsubscribeHeaders() and check isSuppressed() before sending.
 *  2. ALL mail from this service ("block"): blocks sign-in links too, so it is confirmed with one last email containing a
 *     single-use link; only that confirmation adds the address to the blocklist, so nobody can lock another person out.
 *
 * Sign-in and security emails never carry the one-click unsubscribe, only the footer link to level 2.
 */
final class Email
{
    public function __construct(private Legal $legal)
    {
    }

    public static function normalize(string $email): string
    {
        return strtolower(trim($email));
    }

    private function key(): string
    {
        $k = $this->legal->secret;
        if ($k === null && Yii::$app instanceof \yii\web\Application) {
            $k = (string)Yii::$app->request->cookieValidationKey;
        }
        return $k !== null && $k !== '' ? $k : 'rapttor-legal';
    }

    public function sign(string $purpose, string $email): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $purpose . '|' . self::normalize($email), $this->key(), true)), '+/', '-_'), '=');
    }

    public function verify(string $purpose, string $email, string $sig): bool
    {
        return $sig !== '' && hash_equals($this->sign($purpose, $email), $sig);
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public static function unb64(string $s): string
    {
        return (string)base64_decode(strtr($s, '-_', '+/'), true);
    }

    /** Signed one-click URL that stops OPTIONAL mail to this address. */
    public function unsubscribeUrl(string $email): string
    {
        return Url::to(['/' . $this->legal->routePrefix . '/unsubscribe', 'e' => self::b64(self::normalize($email)), 's' => $this->sign('optional', $email)], true);
    }

    /** Signed link to the page that requests blocking ALL mail (still needs the confirmation email). */
    public function stopUrl(string $email): string
    {
        return Url::to(['/' . $this->legal->routePrefix . '/email-stop', 'e' => self::b64(self::normalize($email)), 's' => $this->sign('stop', $email)], true);
    }

    /** @return array<string,string> headers for OPTIONAL mail (RFC 8058) */
    public function unsubscribeHeaders(string $email): array
    {
        $targets = ['<' . $this->unsubscribeUrl($email) . '>'];
        if ($this->legal->unsubscribeMailto !== null) {
            $targets[] = '<mailto:' . $this->legal->unsubscribeMailto . '?subject=unsubscribe>';
        }
        return ['List-Unsubscribe' => implode(', ', $targets), 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'];
    }

    /**
     * Footer for every email. With `Legal::$providerFooter` the "stop all email" link is left out because the provider appends its own.
     *
     * @return array{html:string,text:string}
     */
    public function footer(string $email, bool $optional = false, ?string $lang = null): array
    {
        $product = $this->legal->productName();
        $links = [];
        if ($optional) {
            $links[] = [$this->legal->t('email.optional_stop', [], $lang), $this->unsubscribeUrl($email)];
        }
        if (!$this->legal->providerFooter) {
            $links[] = [$this->legal->t('email.stop_all', ['{product}' => $product], $lang), $this->stopUrl($email)];
        }
        $text = $this->legal->t('email.footer', ['{product}' => $product], $lang);
        $html = Html::encode($text);
        foreach ($links as [$label, $url]) {
            $text .= "\n$label: $url";
            $html .= '<br>' . Html::a(Html::encode($label), $url);
        }
        return ['html' => '<p style="font-size:12px;color:#666">' . $html . '</p>', 'text' => "\n--\n" . $text];
    }

    /** Is this address on the no-send list for this kind of mail? OPTIONAL mail stops for scope optional and all; TRANSACTIONAL only for all. */
    public function isSuppressed(string $email, string $kind = 'optional'): bool
    {
        $scopes = $kind === 'optional' ? ['optional', 'all'] : ['all'];
        return (new Query())->from($this->legal->suppressionTable)->where(['email' => self::normalize($email), 'scope' => $scopes])->exists(Yii::$app->get($this->legal->db));
    }

    public function suppress(string $email, string $scope, string $source): void
    {
        $e = self::normalize($email);
        $db = Yii::$app->get($this->legal->db);
        if (!(new Query())->from($this->legal->suppressionTable)->where(['email' => $e, 'scope' => $scope])->exists($db)) {
            $db->createCommand()->insert($this->legal->suppressionTable, ['email' => $e, 'scope' => $scope, 'source' => $source, 'created_at' => time()])->execute();
        }
    }

    /** Level 1: effective immediately. */
    public function stopOptional(string $email, string $source = 'link'): void
    {
        $this->suppress($email, 'optional', $source);
    }

    /**
     * Level 2, step 1: send the last email with the single-use confirmation link. Returns false when rate-limited (3 per hour).
     * Callers show the same message either way, so the page does not reveal whether an address exists.
     */
    public function requestBlock(string $email, ?string $lang = null): bool
    {
        $e = self::normalize($email);
        if (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $db = Yii::$app->get($this->legal->db);
        $recent = (int)(new Query())->from($this->legal->tokenTable)->where(['email' => $e, 'purpose' => 'block'])->andWhere(['>', 'created_at', time() - 3600])->count('*', $db);
        if ($recent >= 3) {
            return false;
        }
        $raw = bin2hex(random_bytes(24));
        $db->createCommand()->insert($this->legal->tokenTable, ['hash' => hash('sha256', $raw), 'purpose' => 'block', 'email' => $e, 'expires_at' => time() + 86400, 'created_at' => time()])->execute();
        $link = Url::to(['/' . $this->legal->routePrefix . '/email-confirm', 't' => $raw], true);
        $product = $this->legal->productName();
        $body = $this->legal->t('email.confirm_body', ['{product}' => $product, '{email}' => $e, '{link}' => $link], $lang);
        try {
            $this->send($e, $this->legal->t('email.confirm_subject', ['{product}' => $product], $lang),
                '<p>' . nl2br(Html::encode(str_replace($link, '', $body))) . '</p><p>' . Html::a(Html::encode($link), $link) . '</p>', $body);
        } catch (\Throwable $ex) {
            // For example the provider refuses mail to an address that is already blocked (Postar: 422 recipient_unsubscribed).
            Yii::warning('Block confirmation email not sent: ' . $ex->getMessage(), __METHOD__);
            return false;
        }
        return true;
    }

    /** The email a still-valid, unused token belongs to (does not consume it), for the confirmation page. */
    public function peek(string $token): ?string
    {
        $row = $this->row($token);
        return $row['email'] ?? null;
    }

    /**
     * Level 2, step 2 (only from a POST): consume the token, block locally and at the provider.
     *
     * @return string 'done' | 'invalid' | 'done_local_only' (provider refused; local list is in force)
     */
    public function confirmBlock(string $token): string
    {
        $row = $this->row($token);
        if ($row === null) {
            return 'invalid';
        }
        $db = Yii::$app->get($this->legal->db);
        $db->createCommand()->update($this->legal->tokenTable, ['used_at' => time()], ['id' => $row['id']])->execute();
        $this->suppress($row['email'], 'all', 'confirmed');
        try {
            $this->blocklist()->add($row['email'], ['source' => 'rapttor/yii2-legal', 'reason' => 'user request, confirmed by link']);
        } catch (\Throwable $e) {
            Yii::error('Blocklist provider refused ' . $row['email'] . ': ' . $e->getMessage(), __METHOD__);
            return 'done_local_only';
        }
        return 'done';
    }

    public function blocklist(): BlocklistInterface
    {
        $b = $this->legal->blocklist;
        if ($b instanceof BlocklistInterface) {
            return $b;
        }
        return $b === null ? new NullBlocklist() : Yii::createObject($b);
    }

    /** Send an email through `Legal::$mailSender` or the Yii mailer. @param array<string,string> $headers */
    public function send(string $to, string $subject, string $html, string $text, array $headers = []): void
    {
        $sender = $this->legal->mailSender;
        if (is_callable($sender)) {
            $sender($to, $subject, $html, $text, $headers);
            return;
        }
        Yii::$app->get('mailer')->compose()->setTo($to)->setSubject($subject)->setHtmlBody($html)->setTextBody($text)->send();
    }

    /** @return array<string,mixed>|null */
    private function row(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            return null;
        }
        $row = (new Query())->from($this->legal->tokenTable)->where(['hash' => hash('sha256', $token), 'purpose' => 'block', 'used_at' => null])
            ->andWhere(['>', 'expires_at', time()])->one(Yii::$app->get($this->legal->db));
        return $row ?: null;
    }
}
