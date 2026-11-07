<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class Employee extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];
    protected $appends = [
        'name',
    ];

    /**
     * Who may enter a panel. Without this method Filament only allows access in the local
     * environment, so nobody could log in once deployed.
     * - Inactive (terminated) employees cannot log in anywhere.
     * - The admin panel is reserved to the admin role; the portal is open to every active employee.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        // A missing value means the database default (active)
        if (!(bool) ($this->attributes['is_active'] ?? true)) {
            return false;
        }
        return $panel->getId() === 'admin' ? $this->hasRole('admin') : true;
    }

    public function getNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }


    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }
    public function position()
    {
        return $this->belongsTo(Position::class, 'position_id');
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
            'password' => 'hashed',
        ];
    }
}
