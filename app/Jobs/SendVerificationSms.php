<?php

namespace App\Jobs;

use App\Exceptions\AlphaSmsException;
use App\Models\PhoneVerification;
use App\Services\AlphaSms;
use App\Services\MobileAuth;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use SensitiveParameter;

class SendVerificationSms implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 20;

    public function __construct(
        private string $verificationId,
        private string $deliveryId,
        #[SensitiveParameter] private string $code,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 20];
    }

    public function handle(AlphaSms $sms): void
    {
        if (! config('mobile_auth.send_sms')) {
            return;
        }

        $claimedAt = now()->startOfSecond();
        $verification = $this->activeDelivery()->where(fn (Builder $query) => $query->whereNull('sms_claimed_at')
            ->orWhere('sms_claimed_at', '<=', now()->subSeconds(30)));
        $target = $verification->first();
        if ($target === null || ($target->purpose === 'reset' && $target->user_id === null)
            || $verification->update(['sms_claimed_at' => $claimedAt]) !== 1
        ) {
            return;
        }

        $claim = $this->activeDelivery()->where('sms_claimed_at', $claimedAt);
        $requestId = 1 + (int) hexdec(substr(hash('sha256', $this->deliveryId), 0, 13));
        try {
            if ($this->attempts() === 1 || ! $sms->checkExistingDelivery($requestId)) {
                $message = match ($target->language) {
                    'uk' => 'Код TravelGo: ',
                    'de' => 'TravelGo-Code: ',
                    'es' => 'Código de TravelGo: ',
                    'fr' => 'Code TravelGo : ',
                    default => 'TravelGo code: ',
                };
                $sms->send($target->phone, $message.$this->code, $requestId);
            }
            $claim->update(['sms_accepted_at' => now()]);
        } catch (AlphaSmsException $error) {
            $claim->update(['sms_claimed_at' => null]);
            if ($error->retryable) {
                throw $error;
            }

            $this->fail($error);
        }
    }

    /** @return Builder<PhoneVerification> */
    private function activeDelivery(): Builder
    {
        return PhoneVerification::query()->whereKey($this->verificationId)
            ->where('delivery_id', $this->deliveryId)
            ->where('code_hash', MobileAuth::codeHash($this->verificationId, $this->code))
            ->where('expires_at', '>', now())
            ->whereNull('consumed_at')->whereNull('sms_accepted_at');
    }
}
