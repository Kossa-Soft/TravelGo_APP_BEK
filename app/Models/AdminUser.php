<?php

namespace App\Models;

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
        return $this->is_superadmin || in_array($permission, $this->permissions ?? [], true);
    }
}
