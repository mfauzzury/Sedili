<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * The supervisor chosen for this employee (see effectiveSupervisor()).
     */
    public function supervisor()
    {
        return $this->belongsTo(Employee::class, 'supervisor_id');
    }

    public function subordinates()
    {
        return $this->hasMany(Employee::class, 'supervisor_id');
    }

    /**
     * Who the employee reports to: the supervisor chosen for them or, without one, the manager of
     * their department. Only an active employee counts (a supervisor who left is no supervisor), and
     * nobody is their own supervisor. Null when there is none.
     *
     * Load "supervisor" and "department.manager" first when this is called for many employees.
     */
    public function effectiveSupervisor(): ?Employee
    {
        $supervisor = $this->supervisor;
        if ($supervisor !== null && $supervisor->is_active && $supervisor->isNot($this)) {
            return $supervisor;
        }

        $manager = $this->department?->manager;
        if ($manager !== null && $manager->is_active && $manager->isNot($this)) {
            return $manager;
        }

        return null;
    }

    /**
     * Employees without a supervisor (same rule as effectiveSupervisor(), in SQL).
     */
    public function scopeWithoutSupervisor(Builder $query): Builder
    {
        return $query
            ->whereNotExists(fn($sub) => self::chosenSupervisorIsValid($sub))
            ->whereNotExists(
                fn($sub) => $sub->selectRaw('1')->from('departments as dep')
                    ->join('employees as mgr', 'mgr.id', '=', 'dep.manager_id')
                    ->whereColumn('dep.id', 'employees.department_id')
                    ->whereColumn('mgr.id', '!=', 'employees.id')
                    ->where('mgr.is_active', true)
                    ->whereNull('mgr.deleted_at')
            );
    }

    /**
     * The employees who report to the given one (same rule as effectiveSupervisor(), in SQL): those
     * who chose them as supervisor and, among those without a valid chosen supervisor, the members
     * of the departments they manage. Nobody else, and nobody reports to an inactive employee.
     */
    public function scopeReportingTo(Builder $query, Employee $boss): Builder
    {
        // A missing value means the database default (active), as in canAccessPanel()
        if (!(bool) ($boss->getAttributes()['is_active'] ?? true)) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('employees.id', '!=', $boss->id)
            ->where(
                fn(Builder $query) => $query
                    ->where('employees.supervisor_id', $boss->id)
                    ->orWhere(
                        fn(Builder $query) => $query
                            ->whereNotExists(fn($sub) => self::chosenSupervisorIsValid($sub))
                            ->whereIn('employees.department_id', Department::query()->where('manager_id', $boss->id)->select('id'))
                    )
            );
    }

    /**
     * Sub-query: the supervisor chosen for the employee of the outer query exists and is active.
     */
    private static function chosenSupervisorIsValid($sub)
    {
        return $sub->selectRaw('1')->from('employees as sup')
            ->whereColumn('sup.id', 'employees.supervisor_id')
            ->whereColumn('sup.id', '!=', 'employees.id')
            ->where('sup.is_active', true)
            ->whereNull('sup.deleted_at');
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
