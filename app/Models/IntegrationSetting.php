<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Hidden(['api_key'])]
class IntegrationSetting extends Model
{
    protected function casts(): array
    {
        return ['api_key' => 'encrypted'];
    }
}
