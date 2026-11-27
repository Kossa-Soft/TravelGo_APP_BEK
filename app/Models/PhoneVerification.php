<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

class PhoneVerification extends Model
{
    use MassPrunable;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = ['pending_password_hash', 'code_hash', 'reset_token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'resend_at' => 'datetime',
            'consumed_at' => 'datetime',
            'sms_claimed_at' => 'datetime',
            'sms_accepted_at' => 'datetime',
            'reset_expires_at' => 'datetime',
        ];
    }

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDay())
            ->where(fn (Builder $query) => $query->whereNull('reset_expires_at')->orWhere('reset_expires_at', '<=', now()));
    }
}
