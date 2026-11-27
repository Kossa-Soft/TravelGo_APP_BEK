<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Hidden(['api_key'])]
class IntegrationSetting extends Model
{
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'balance_amount' => 'decimal:4',
            'balance_checked_at' => 'immutable_datetime',
            'last_sms_accepted_at' => 'immutable_datetime',
            'last_sms_error_at' => 'immutable_datetime',
            'last_sms_request_id' => 'integer',
        ];
    }
}
