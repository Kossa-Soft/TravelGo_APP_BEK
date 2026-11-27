<?php

namespace App\Exceptions;

use RuntimeException;

class AlphaSmsException extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly bool $retryable = false)
    {
        parent::__construct(match ($reason) {
            'settings_missing' => 'AlphaSMS settings are missing.',
            'unreachable' => 'AlphaSMS could not be reached.',
            'rate_limited' => 'AlphaSMS temporarily limited requests.',
            'sender_rejected' => 'AlphaSMS rejected the sender.',
            'insufficient_balance' => 'AlphaSMS reported insufficient balance.',
            'invalid_key' => 'AlphaSMS did not accept the API key.',
            default => 'AlphaSMS rejected the request.',
        });
    }

    public static function label(string $reason): string
    {
        return match ($reason) {
            'settings_missing' => 'Не заполнены настройки AlphaSMS',
            'unreachable' => 'AlphaSMS временно недоступен',
            'rate_limited' => 'AlphaSMS временно ограничил запросы',
            'sender_rejected' => 'AlphaSMS отклонил имя отправителя',
            'insufficient_balance' => 'AlphaSMS сообщил о недостаточном балансе',
            'invalid_key' => 'AlphaSMS отклонил API-ключ',
            default => 'AlphaSMS отклонил SMS',
        };
    }
}
