<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'email_verified_at',
        'phone',
        'whatsapp',
        'nin',
        'nin_verified_at',
        'date_of_birth',
        'password',
        'avatar',
        'google_id',
        'onboarded_at',
        'transaction_pin',
        'is_admin',
        'is_active',
        'last_login_at',
        'password_changed_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'transaction_pin',
        'nin',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'nin_verified_at' => 'datetime',
            'onboarded_at' => 'datetime',
            'date_of_birth' => 'date',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function member(): HasOne
    {
        return $this->hasOne(Member::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(MemberNotification::class);
    }

    /**
     * Roles assigned to this user. Role/permission pairs live in the database
     * (PRD 31 - granular, individually configurable permissions).
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user')->withTimestamps();
    }

    /**
     * Memoised roles + their permissions, loaded once per request so the
     * admin middleware never fans out into N+1 queries.
     *
     * @var \Illuminate\Support\Collection|null
     */
    protected $rolePermissionGraph = null;

    protected function roleGraph()
    {
        if ($this->rolePermissionGraph === null) {
            $this->rolePermissionGraph = $this->roles()
                ->where('roles.is_active', true)
                ->with('permissions')
                ->get();
        }

        return $this->rolePermissionGraph;
    }

    public function forgetPermissionGraph(): void
    {
        $this->rolePermissionGraph = null;
    }

    /** @return array<int, string> */
    public function roleCodes(): array
    {
        return $this->roleGraph()->pluck('code')->values()->all();
    }

    /** @return array<int, string> */
    public function permissionCodes(): array
    {
        return $this->roleGraph()
            ->flatMap(fn (Role $role) => $role->permissions->pluck('code'))
            ->unique()
            ->values()
            ->all();
    }

    public function hasRole(string $code): bool
    {
        return in_array($code, $this->roleCodes(), true);
    }

    /**
     * Super Admin holds every permission regardless of role configuration.
     */
    public function hasPermission(string $code): bool
    {
        if ($this->hasRole(Role::SUPER_ADMIN)) {
            return true;
        }

        return in_array($code, $this->permissionCodes(), true);
    }

    /**
     * True when the user can enter the admin portal at all. The legacy
     * is_admin flag is honoured so existing rows keep working, but the real
     * test is holding at least one assigned role.
     */
    public function isAdmin(): bool
    {
        if ($this->is_admin) {
            return true;
        }

        return $this->roleCodes() !== [];
    }
}
