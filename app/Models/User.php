<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    use HasRoles;

    public function isAdmin(): bool
    {
        return $this->is_active && $this->hasRole('Super Admin');
    }

    public function canEmailCandidates(): bool
    {
        return $this->is_active
            && $this->hasAnyRole(['Super Admin', 'HR Executive'])
            && $this->can('candidates.view')
            && $this->can('candidates.update');
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && $this->hasAnyRole(['Super Admin', 'HR Executive']);
    }

    public function candidates()
    {
        return $this->hasMany(Candidate::class, 'assigned_hr_id');
    }

    public function callLogs()
    {
        return $this->hasMany(CallLog::class, 'hr_id');
    }

    public function followUps()
    {
        return $this->hasMany(FollowUp::class, 'assigned_hr_id');
    }

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'mobile',
        'is_active',
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

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
