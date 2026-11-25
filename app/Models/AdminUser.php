<?php

namespace App\Models;

use App\Support\TravelGoType;
use Database\Factories\AdminUserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

#[Hidden(['password', 'totp_secret', 'last_totp_step'])]
class AdminUser extends Authenticatable
{
    /** @use HasFactory<AdminUserFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'totp_secret' => 'encrypted',
            'is_superadmin' => 'boolean',
            'permissions' => 'array',
        ];
    }

    public function hasPermission(string $permission): bool
    {
        return $this->is_superadmin
            || in_array($permission, $this->permissions ?? [], true)
            || (str_starts_with($permission, 'travelgo.') && in_array('travelgo.*', $this->permissions ?? [], true));
    }

    public function canOpenTravelGo(): bool
    {
        if ($this->is_superadmin) {
            return true;
        }

        return collect($this->permissions ?? [])->contains(fn (string $permission): bool => str_starts_with($permission, 'travelgo.'));
    }

    public function canBrowseTravelGo(TravelGoType $type): bool
    {
        return $this->hasPermission($type->permission('view'));
    }
}
