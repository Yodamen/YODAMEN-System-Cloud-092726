<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'code',
        'business_id',
        'default_branch_id',
        'role_id',
        'username',
        'name',
        'display_name',
        'email',
        'password',
        'status',
        'force_reset',
        'failed_attempts',
        'locked_until',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'force_reset' => 'boolean',
            'failed_attempts' => 'integer',
            'locked_until' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function defaultBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'default_branch_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function isActive(): bool
    {
        return strtoupper((string) $this->status) === 'ACTIVE';
    }

    public function isSuperAdmin(): bool
    {
        return (bool) $this->role?->is_super_admin;
    }

    public function permissionCodes(): array
    {
        if (! $this->role || ! $this->role->active) {
            return [];
        }

        return $this->role->permissions()
            ->orderBy('module')
            ->orderBy('action')
            ->pluck('code')
            ->all();
    }

    public function hasPermission(string $module, string $action): bool
    {
        if (! $this->isActive() || ! $this->role || ! $this->role->active) {
            return false;
        }

        if ($this->role->is_super_admin) {
            return true;
        }

        $module = strtolower(trim($module));
        $action = strtoupper(trim($action));

        return $this->role->permissions()
            ->where('module', $module)
            ->where('action', $action)
            ->exists();
    }
}
