<?php

namespace App\Jobs;

use App\Models\PhoneVerification;
use App\Services\AlphaSms;
use App\Services\MobileAuth;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use SensitiveParameter;

class SendVerificationSms implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 15;

    public function __construct(
        private string $verificationId,
        private string $deliveryId,
        #[SensitiveParameter] private string $code,
    ) {}

    public function handle(AlphaSms $sms): void
    {
        if (! config('mobile_auth.send_sms')) {
            return;
        }

        $verification = PhoneVerification::query()->whereKey($this->verificationId)
            ->where('delivery_id', $this->deliveryId)
            ->where('code_hash', MobileAuth::codeHash($this->verificationId, $this->code))
            ->where('expires_at', '>', now())
            ->whereNull('consumed_at')->whereNull('sms_claimed_at');

        $target = $verification->first();
        if ($target === null || ($target->purpose === 'reset' && $target->user_id === null)) {
            return;
        }

        if ($verification->update(['sms_claimed_at' => now()]) !== 1) {
            return;
        }

        $message = match ($target->language) {
            'uk' => 'Код TravelGo: ',
            'de' => 'TravelGo-Code: ',
            'es' => 'Código de TravelGo: ',
            'fr' => 'Code TravelGo : ',
            default => 'TravelGo code: ',
        };

        $sms->send($target->phone, $message.$this->code);
    }
}
