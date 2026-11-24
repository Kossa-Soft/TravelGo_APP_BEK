<?php

namespace App\Jobs;

use App\Services\AlphaSms;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use SensitiveParameter;

class SendPhoneChangedSms implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 15;

    public function __construct(#[SensitiveParameter] private string $phone, private string $language) {}

    public function handle(AlphaSms $sms): void
    {
        if (! config('mobile_auth.send_sms') || (app()->environment('local', 'testing', 'staging')
                && $this->phone === config('mobile_auth.demo_phone'))) {
            return;
        }

        $sms->send($this->phone, match ($this->language) {
            'uk' => 'TravelGo: номер телефону вашого акаунта змінено.',
            'de' => 'TravelGo: Die Telefonnummer Ihres Kontos wurde geändert.',
            'es' => 'TravelGo: cambió el número de teléfono de tu cuenta.',
            'fr' => 'TravelGo : le numéro de téléphone de votre compte a changé.',
            default => 'TravelGo: your account phone number has changed.',
        });
    }
}
