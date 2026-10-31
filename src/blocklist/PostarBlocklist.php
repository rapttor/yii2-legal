<?php

declare(strict_types=1);

namespace rapttor\yii2legal\blocklist;

use yii\base\BaseObject;

/**
 * Adds addresses to the blocklist of a Postar project (postar.vip).
 *
 * Postar publishes the send API (`POST /v1/send`, Bearer project key) and blocks recipients who unsubscribe through its own
 * links. Its endpoint for adding an address to the blocklist is not public yet, so this adapter takes the path from
 * configuration and refuses to guess. When Postar documents it (see https://postar.vip/llm.txt and /openapi.json), set:
 *
 *     'blocklist' => [
 *         'class' => PostarBlocklist::class,
 *         'endpoint' => '/v1/<path from the Postar docs>',
 *         'payload' => fn(string $email, array $ctx): array => ['email' => $email],   // adjust to the documented fields
 *     ],
 *
 * The key comes from POSTAR_API_KEY (never in code, never in the browser).
 */
final class PostarBlocklist extends BaseObject implements BlocklistInterface
{
    public string $baseUrl = 'https://api.postar.vip';
    public ?string $apiKey = null;
    public ?string $endpoint = null;
    public string $method = 'POST';
    /** @var callable|null fn(string $email, array $context): array body fields */
    public $payload = null;
    /** @var callable|null fn(string $method, string $url, array $headers, string $body): array{0:int,1:string} status and body; for tests and custom HTTP clients */
    public $transport = null;
    public int $timeout = 10;

    public function add(string $email, array $context = []): void
    {
        if ($this->endpoint === null || $this->endpoint === '') {
            throw new \LogicException('Postar blocklist endpoint is not configured: take the path from the Postar API docs (https://postar.vip/docs/api).');
        }
        $key = $this->apiKey ?? (getenv('POSTAR_API_KEY') ?: null);
        if ($key === null) {
            throw new \LogicException('POSTAR_API_KEY is not set.');
        }
        $body = json_encode($this->payload ? ($this->payload)($email, $context) : ['email' => $email], JSON_THROW_ON_ERROR);
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($this->endpoint, '/');
        $headers = ['Authorization: Bearer ' . $key, 'Content-Type: application/json', 'Accept: application/json'];
        [$status, $response] = $this->transport ? ($this->transport)($this->method, $url, $headers, $body) : $this->curl($url, $headers, $body);
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("Postar refused the blocklist request (HTTP $status): " . substr($response, 0, 200));
        }
    }

    /** @param list<string> $headers @return array{0:int,1:string} */
    private function curl(string $url, array $headers, string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $this->method, CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $this->timeout, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP]);
        $out = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$status, is_string($out) ? $out : ''];
    }
}
