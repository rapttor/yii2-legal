<?php

declare(strict_types=1);

namespace rapttor\yii2legal\blocklist;

use yii\base\BaseObject;

/**
 * Blocklist of a Postar project (postar.vip), through its documented unsubscribe endpoints (https://postar.vip/llm.txt):
 *
 *   POST   /v1/unsubscribes          {"email": "..."}   201 created, 200 already blocked
 *   GET    /v1/unsubscribes?email=   {"email","unsubscribed","source","since"}
 *   DELETE /v1/unsubscribes?email=   lifts a block this app placed (source "api"); 409 when the recipient placed it
 *
 * Authentication is the project key as a Bearer token (`POSTAR_API_KEY`, never in the browser or in git).
 *
 * Things to know about Postar, because they shape how email is wired:
 *  - A block applies to ALL mail from the project to that address, sign-in links included.
 *  - Postar adds its own unsubscribe link and List-Unsubscribe headers to every email and does not let you turn it off.
 *    Do not build a second link: set `Legal::$providerFooter = true` so our footer leaves out the "stop all email" link.
 *  - Postar is for transactional mail. Send optional news from a different provider or a separate project, so that one
 *    person's "no more news" can never block their sign-in emails.
 *  - Sending to a blocked address returns 422 `recipient_unsubscribed` with a `resubscribe_url`: show that URL, do not retry.
 *
 *     'blocklist' => ['class' => PostarBlocklist::class],     // the key comes from POSTAR_API_KEY
 */
final class PostarBlocklist extends BaseObject implements BlocklistInterface
{
    public string $baseUrl = 'https://api.postar.vip';
    public ?string $apiKey = null;
    public string $endpoint = '/v1/unsubscribes';
    /** @var callable|null fn(string $method, string $url, array $headers, string $body): array{0:int,1:string} status and body; for tests and custom HTTP clients */
    public $transport = null;
    public int $timeout = 10;

    /** Block the address for the whole project (idempotent: 201 first time, 200 afterwards). */
    public function add(string $email, array $context = []): void
    {
        [$status, $body] = $this->call('POST', '', json_encode(['email' => $email], JSON_THROW_ON_ERROR));
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("Postar refused the blocklist request (HTTP $status): " . substr($body, 0, 200));
        }
    }

    /** @return array{email?:string,unsubscribed?:bool,source?:string,since?:string}|null null when Postar does not answer with JSON */
    public function status(string $email): ?array
    {
        [$status, $body] = $this->call('GET', '?email=' . rawurlencode($email));
        $d = json_decode($body, true);
        return $status === 200 && is_array($d) ? $d : null;
    }

    /**
     * Lift a block that this app placed. A block the recipient placed (source "recipient") cannot be lifted: Postar answers
     * 409 and the person must use the `resubscribe_url`.
     *
     * @return array{ok:bool,resubscribeUrl:?string}
     */
    public function remove(string $email): array
    {
        [$status, $body] = $this->call('DELETE', '?email=' . rawurlencode($email));
        if ($status === 409) {
            $d = json_decode($body, true);
            return ['ok' => false, 'resubscribeUrl' => is_array($d) ? ($d['resubscribe_url'] ?? null) : null];
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("Postar refused to lift the block (HTTP $status): " . substr($body, 0, 200));
        }
        return ['ok' => true, 'resubscribeUrl' => null];
    }

    /** @return array{0:int,1:string} */
    private function call(string $method, string $query, string $body = ''): array
    {
        $key = $this->apiKey ?? (getenv('POSTAR_API_KEY') ?: null);
        if ($key === null) {
            throw new \LogicException('POSTAR_API_KEY is not set.');
        }
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($this->endpoint, '/') . $query;
        $headers = ['Authorization: Bearer ' . $key, 'Accept: application/json'] + ($body !== '' ? [2 => 'Content-Type: application/json'] : []);
        return $this->transport ? ($this->transport)($method, $url, array_values($headers), $body) : $this->curl($method, $url, array_values($headers), $body);
    }

    /** @param list<string> $headers @return array{0:int,1:string} */
    private function curl(string $method, string $url, array $headers, string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $out = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$status, is_string($out) ? $out : ''];
    }
}
