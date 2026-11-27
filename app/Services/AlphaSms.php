<?php

namespace App\Services;

use App\Exceptions\AlphaSmsException;
use App\Models\IntegrationSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;

class AlphaSms
{
    private const string URL = 'https://api.alphasms.ua/api/json.php';

    private const array DELIVERY_STATUSES = [
        'ACCEPTED', 'DELETED', 'DELIVERED', 'EXPIRED', 'FILTERED', 'INVALID DESTINATION ADDRESS',
        'INVALID SOURCE ADDRESS', 'NO ROUTE', 'PARTIALLY DELIVERED', 'QUEUED', 'REJECTED',
        'ROUTING', 'SIM FULL', 'UNDELIVERABLE', 'UNKNOWN',
    ];

    public function send(#[SensitiveParameter] string $phone, #[SensitiveParameter] string $message, ?int $requestId = null): void
    {
        $requestId ??= random_int(1, 9_007_199_254_740_991);
        $settings = null;
        try {
            $settings = $this->settings();
            if (! $settings->sender) {
                throw new AlphaSmsException('settings_missing');
            }
            $this->request($settings, [[
                'type' => 'sms', 'id' => $requestId, 'phone' => (int) substr($phone, 1),
                'sms_signature' => $settings->sender, 'sms_message' => $message, 'sms_lifetime' => 3600,
            ]]);
        } catch (AlphaSmsException $exception) {
            $diagnostics = $settings === null ? IntegrationSetting::query()->where('provider', 'alphasms') : $this->diagnostics($settings);
            $diagnostics->toBase()->update(['last_sms_error' => $exception->reason, 'last_sms_error_at' => now()]);

            throw $exception;
        }

        $this->recordAcceptance($settings, $requestId);
    }

    public function checkExistingDelivery(int $requestId): bool
    {
        try {
            $settings = $this->settings();
            $status = $this->deliveryStatus($settings, $requestId);
        } catch (AlphaSmsException) {
            return false;
        }
        if ($status === null) {
            return false;
        }

        $this->recordAcceptance($settings, $requestId, $status);

        return true;
    }

    public function refreshBalance(IntegrationSetting $settings): void
    {
        $result = $this->request($settings, [['type' => 'balance']]);
        $amount = $result->json('data.0.data.amount');
        $currency = $result->json('data.0.data.currency');
        if (! is_numeric($amount) || ! is_finite((float) $amount) || abs((float) $amount) >= 1_000_000_000_000) {
            throw new AlphaSmsException('rejected');
        }
        $this->diagnostics($settings)->toBase()->update([
            'balance_amount' => (float) $amount,
            'balance_currency' => is_string($currency) && preg_match('/\A[A-Z]{3}\z/', $currency) ? $currency : null,
            'balance_checked_at' => now(),
        ]);

        if ($settings->last_sms_request_id !== null) {
            try {
                $status = $this->deliveryStatus($settings, $settings->last_sms_request_id);
                $this->diagnostics($settings)->where('last_sms_request_id', $settings->last_sms_request_id)->toBase()->update([
                    'last_sms_delivery_status' => $status,
                ]);
            } catch (AlphaSmsException) {
                // Balance is available even when delivery status could not be refreshed.
            }
        }
    }

    private function deliveryStatus(IntegrationSetting $settings, int $requestId): ?string
    {
        $response = $this->request($settings, [['type' => 'status', 'id' => $requestId]], timeout: 4);
        $status = $response->json('data.0.data.status');

        return is_string($status) && in_array($status, self::DELIVERY_STATUSES, true) ? $status : null;
    }

    private function recordAcceptance(IntegrationSetting $settings, int $requestId, string $status = 'ACCEPTED'): void
    {
        $this->diagnostics($settings)->toBase()->update([
            'last_sms_request_id' => $requestId, 'last_sms_accepted_at' => now(), 'last_sms_delivery_status' => $status,
        ]);
    }

    private function settings(): IntegrationSetting
    {
        return IntegrationSetting::query()->where('provider', 'alphasms')->first()
            ?? throw new AlphaSmsException('settings_missing');
    }

    private function diagnostics(IntegrationSetting $settings): Builder
    {
        return IntegrationSetting::query()->whereKey($settings->id)->where('api_key', $settings->getRawOriginal('api_key'));
    }

    private function request(#[SensitiveParameter] IntegrationSetting $settings, #[SensitiveParameter] array $data, int $timeout = 8): Response
    {
        if (! $settings->api_key) {
            throw new AlphaSmsException('settings_missing');
        }

        try {
            $response = Http::asJson()->acceptJson()->connectTimeout(3)->timeout($timeout)->withoutRedirecting()
                ->post(self::URL, ['auth' => $settings->api_key, 'data' => $data]);
        } catch (ConnectionException) {
            throw new AlphaSmsException('unreachable', retryable: true);
        }

        if ($response->status() === 429) {
            throw new AlphaSmsException('rate_limited', retryable: true);
        }
        if ($response->serverError() || $response->status() === 408) {
            throw new AlphaSmsException('unreachable', retryable: true);
        }
        if (! $response->successful() || $response->json('success') !== true || $response->json('data.0.success') !== true) {
            throw new AlphaSmsException($this->classifyRejection($response));
        }

        return $response;
    }

    private function classifyRejection(#[SensitiveParameter] Response $response): string
    {
        $message = $response->json('data.0.error') ?? $response->json('error');
        $text = is_string($message) ? strtolower($message) : '';

        return match (true) {
            in_array($response->status(), [401, 403], true), str_contains($text, 'access denied') => 'invalid_key',
            str_contains($text, 'signature'), str_contains($text, 'sender') => 'sender_rejected',
            str_contains($text, 'balance'), str_contains($text, 'not enough money') => 'insufficient_balance',
            default => 'rejected',
        };
    }
}
