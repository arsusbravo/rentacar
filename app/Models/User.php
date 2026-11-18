<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
        ];
    }

    /** Back office users that may be managed: staff, without the hidden accounts (config admin.hidden_emails). */
    public function scopeManageable(Builder $query): Builder
    {
        return $query->whereIn('role', array_map(fn (Role $r) => $r->value, Role::staff()))
            ->whereNotIn('email', config('admin.hidden_emails', []));
    }

    public function hasRole(Role ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(Role::Admin);
    }

    public function isEmployee(): bool
    {
        return $this->hasRole(Role::Employee);
    }

    public function isClient(): bool
    {
        return $this->hasRole(Role::Client);
    }

    /** Admins and employees may use the back office. */
    public function isStaff(): bool
    {
        return $this->hasRole(...Role::staff());
    }
}
