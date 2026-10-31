<?php

declare(strict_types=1);

namespace rapttor\yii2legal;

use rapttor\yii2legal\events\WithdrawalEvent;
use Yii;

/**
 * Withdrawal function for consumers (EU Consumer Rights Directive Art. 11a, from 19 June 2026): form, confirmation step,
 * acknowledgement email on a durable medium, and an event for your refund handling.
 *
 *     Yii::$app->legal->on(Legal::EVENT_WITHDRAWAL, function (WithdrawalEvent $e) { ... end the contract, refund ... });
 */
final class Withdrawal
{
    public function __construct(private Legal $legal)
    {
    }

    /** @return array{id:int,at:int} */
    public function record(string $contract, string $email, ?string $name = null, ?string $lang = null): array
    {
        $db = Yii::$app->get($this->legal->db);
        $now = time();
        $email = Email::normalize($email);
        $db->createCommand()->insert($this->legal->withdrawalTable, [
            'contract' => mb_substr($contract, 0, 190), 'email' => $email, 'name' => $name === null ? null : mb_substr($name, 0, 190),
            'requested_at' => $now, 'ip_hash' => $this->legal->acceptance()->hashedIp(),
        ])->execute();
        $id = (int)$db->getLastInsertID();
        $date = gmdate('Y-m-d H:i', $now) . ' UTC';
        $body = $this->legal->t('withdraw.mail_body', ['{contract}' => $contract, '{date}' => $date], $lang);
        try {
            $this->legal->email()->send($email, $this->legal->t('withdraw.mail_subject', [], $lang), '<p>' . nl2br(\yii\helpers\Html::encode($body)) . '</p>', $body);
            $db->createCommand()->update($this->legal->withdrawalTable, ['acknowledged_at' => time()], ['id' => $id])->execute();
        } catch (\Throwable $e) {
            Yii::error('Withdrawal acknowledgement not sent: ' . $e->getMessage(), __METHOD__);
        }
        $this->legal->trigger(Legal::EVENT_WITHDRAWAL, new WithdrawalEvent(['id' => $id, 'contract' => $contract, 'email' => $email, 'customerName' => $name, 'requestedAt' => $now]));
        return ['id' => $id, 'at' => $now];
    }
}
