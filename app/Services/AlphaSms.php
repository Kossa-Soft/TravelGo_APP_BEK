<?php

namespace App\Services;

use App\Models\IntegrationSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SensitiveParameter;

class AlphaSms
{
    public function send(#[SensitiveParameter] string $phone, #[SensitiveParameter] string $message): void
    {
        $settings = IntegrationSetting::query()->where('provider', 'alphasms')->first();

        if ($settings?->api_key === null || ! $settings->sender) {
            throw new RuntimeException('AlphaSMS SMS settings are missing.');
        }

        try {
            $response = Http::asJson()->acceptJson()->connectTimeout(3)->timeout(8)->withoutRedirecting()
                ->post('https://api.alphasms.ua/api/json.php', [
                    'auth' => $settings->api_key,
                    'data' => [[
                        'type' => 'sms',
                        'id' => random_int(1, 9_007_199_254_740_991),
                        'phone' => (int) substr($phone, 1),
                        'sms_signature' => $settings->sender,
                        'sms_message' => $message,
                        'sms_lifetime' => 3600,
                    ]],
                ]);
        } catch (ConnectionException) {
            throw new RuntimeException('AlphaSMS could not be reached.');
        }

        if (! $response->successful() || $response->json('success') !== true || $response->json('data.0.success') !== true) {
            throw new RuntimeException('AlphaSMS rejected the SMS.');
        }
    }
}
