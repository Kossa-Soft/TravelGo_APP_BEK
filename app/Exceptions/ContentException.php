<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ContentException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->is('admin/v1/*') || $request->expectsJson()) {
            return response()->json(['error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'details' => $this->details,
            ]], $this->status);
        }

        return back()->withInput()->withErrors(array_merge([$this->getMessage()], $this->details['problems'] ?? []));
    }
}
