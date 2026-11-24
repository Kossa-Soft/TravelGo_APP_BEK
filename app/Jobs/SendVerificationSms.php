<?php

namespace App\Jobs;

use App\Models\IntegrationSetting;
use App\Models\PhoneVerification;
use App\Services\MobileAuth;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
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

    public function handle(): void
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

        $settings = IntegrationSetting::query()->where('provider', 'alphasms')->first();
        if ($settings?->api_key === null || ! $settings->sender) {
            throw new RuntimeException('AlphaSMS SMS settings are missing.');
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

        try {
            $response = Http::asJson()->acceptJson()->connectTimeout(3)->timeout(8)->withoutRedirecting()
                ->post('https://api.alphasms.ua/api/json.php', [
                    'auth' => $settings->api_key,
                    'data' => [[
                        'type' => 'sms',
                        'id' => random_int(1, 9_007_199_254_740_991),
                        'phone' => (int) substr($target->phone, 1),
                        'sms_signature' => $settings->sender,
                        'sms_message' => $message.$this->code,
                        'sms_lifetime' => 3600,
                    ]],
                ]);
        } catch (ConnectionException) {
            throw new RuntimeException('AlphaSMS could not be reached.');
        }

        if (! $response->successful() || $response->json('success') !== true || $response->json('data.0.success') !== true) {
            throw new RuntimeException('AlphaSMS rejected the verification SMS.');
        }
    }
}
