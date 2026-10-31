<?php

declare(strict_types=1);

namespace rapttor\yii2legal;

use rapttor\yii2legal\events\RequestEvent;
use Yii;
use yii\db\Query;

/**
 * Privacy and legal requests (access, export, correction, deletion, objection, copyright and abuse notices, other) from the
 * public form `/legal/request`. Each request is stored with a due date where the law sets one, the responsible mailbox is
 * emailed at once, and `Legal::EVENT_REQUEST` is raised so the host app can alert its admins (chat, ticket, backoffice badge).
 * The public pages promise no reply time; the due date is an internal reminder.
 *
 *     Yii::$app->legal->on(Legal::EVENT_REQUEST, function (RequestEvent $e) { ... notify the admins ... });
 *     Yii::$app->legal->requests()->open();            // for your backoffice
 *     Yii::$app->legal->requests()->close($id, 'done');
 */
final class Requests
{
    public const TYPES = ['access', 'export', 'correct', 'delete', 'object', 'copyright', 'abuse', 'other'];
    private const PRIVACY = ['access', 'export', 'correct', 'delete', 'object'];

    public function __construct(private Legal $legal)
    {
    }

    /**
     * @return array{ok:bool,id?:int,ref?:string,error?:string} error: `invalid` or `limit`
     */
    public function submit(string $type, string $email, string $message, ?string $name = null, ?int $userId = null, ?string $lang = null): array
    {
        $type = in_array($type, self::TYPES, true) ? $type : 'other';
        $email = Email::normalize($email);
        $message = trim($message);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($message) < 5) {
            return ['ok' => false, 'error' => 'invalid'];
        }
        $db = Yii::$app->get($this->legal->db);
        $ip = $this->legal->evidence('')['ip'];
        $since = time() - 3600;
        if ($ip !== null && (new Query())->from($this->legal->requestTable)->where(['ip_hash' => $ip])->andWhere(['>', 'created_at', $since])->count('*', $db) >= $this->legal->requestsPerHour) {
            return ['ok' => false, 'error' => 'limit'];
        }
        $now = time();
        $days = $this->legal->requestDueDays[$type] ?? null;
        $db->createCommand()->insert($this->legal->requestTable, [
            'type' => $type, 'email' => $email, 'name' => $name === null ? null : mb_substr($name, 0, 190), 'message' => mb_substr($message, 0, 8000),
            'status' => 'open', 'user_id' => $userId, 'lang' => $lang ?? $this->legal->currentLanguage(), 'ip_hash' => $ip, 'ua_hash' => $this->legal->evidence('')['ua'],
            'created_at' => $now, 'due_at' => $days === null ? null : $now + $days * 86400,
        ])->execute();
        $id = (int)$db->getLastInsertID();
        $this->notify($id, $type, $email, $message, $name);
        $this->legal->trigger(Legal::EVENT_REQUEST, new RequestEvent(['id' => $id, 'type' => $type, 'email' => $email, 'requesterName' => $name, 'message' => $message, 'userId' => $userId, 'createdAt' => $now]));
        return ['ok' => true, 'id' => $id, 'ref' => self::ref($id)];
    }

    public static function ref(int $id): string
    {
        return 'REQ-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
    }

    /** Open requests, soonest due first (no due date last). @return list<array<string,mixed>> */
    public function open(int $limit = 100): array
    {
        return (new Query())->from($this->legal->requestTable)->where(['status' => 'open'])
            ->orderBy(['(due_at IS NULL)' => SORT_ASC, 'due_at' => SORT_ASC, 'id' => SORT_ASC])->limit($limit)->all(Yii::$app->get($this->legal->db));
    }

    /** @return list<array<string,mixed>> open requests past their due date */
    public function overdue(): array
    {
        return array_values(array_filter($this->open(1000), static fn(array $r): bool => $r['due_at'] !== null && (int)$r['due_at'] < time()));
    }

    /** Mark a request handled. @param string $status done|rejected */
    public function close(int $id, string $status = 'done', ?string $note = null): bool
    {
        $status = $status === 'rejected' ? 'rejected' : 'done';
        return Yii::$app->get($this->legal->db)->createCommand()->update($this->legal->requestTable, [
            'status' => $status, 'closed_at' => time(), 'note' => $note === null ? null : mb_substr($note, 0, 2000),
        ], ['id' => $id, 'status' => 'open'])->execute() > 0;
    }

    /** The mailbox that handles this kind of request. */
    public function mailbox(string $type): string
    {
        $c = $this->legal->effectiveConfig()['company'] ?? [];
        $box = in_array($type, self::PRIVACY, true) ? ($c['privacy_email'] ?? '') : (in_array($type, ['copyright', 'abuse'], true) ? ($c['legal_email'] ?? '') : '');
        return (string)($box !== '' ? $box : ($c['email'] ?? ''));
    }

    private function notify(int $id, string $type, string $email, string $message, ?string $name): void
    {
        $to = $this->mailbox($type);
        if ($to === '') {
            return;
        }
        $ref = self::ref($id);
        $site = (string)($this->legal->effectiveConfig()['company']['name'] ?? '');
        $body = "New $type request $ref on $site\nFrom: " . ($name ? "$name <$email>" : $email) . "\n\n$message\n";
        try {
            $this->legal->email()->send($to, "[$site] New $type request $ref", '<pre>' . \yii\helpers\Html::encode($body) . '</pre>', $body);
        } catch (\Throwable $e) {
            Yii::error('Legal request notification not sent: ' . $e->getMessage(), __METHOD__);
        }
    }
}
