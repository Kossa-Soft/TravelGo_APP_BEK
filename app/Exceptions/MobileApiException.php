<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class MobileApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
        public readonly ?string $field = null,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        $error = ['code' => $this->errorCode, 'message' => $this->getMessage()];
        if ($this->field !== null) {
            $error['field'] = $this->field;
        }

        $response = response()->json(['error' => $error + ['details' => (object) $this->details]], $this->status)
            ->header('Cache-Control', 'no-store');

        if (isset($this->details['retryAfter'])) {
            $response->header('Retry-After', (string) $this->details['retryAfter']);
        }

        return $response;
    }
}
