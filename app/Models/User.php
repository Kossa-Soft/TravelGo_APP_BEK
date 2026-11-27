<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $attributes = ['plan' => 'free'];

    protected static function booted(): void
    {
        static::creating(fn (User $user) => $user->public_id ??= (string) Str::uuid());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'birth_date' => 'date',
            'plan_until' => 'immutable_datetime',
            'blocked_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'password' => 'hashed',
        ];
    }

    public function isBlocked(): bool
    {
        return $this->blocked_at !== null;
    }

    public function effectivePlan(): string
    {
        return $this->plan === 'pro' && ($this->plan_until === null || $this->plan_until->isFuture()) ? 'pro' : 'free';
    }

    public function displayName(): ?string
    {
        $name = implode(' ', array_filter([$this->last_name, $this->first_name, $this->middle_name]));

        return $name !== '' ? $name : $this->name;
    }
}
