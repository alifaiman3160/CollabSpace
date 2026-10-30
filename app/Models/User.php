<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Mail\PasswordResetLink;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Mail;

class User extends Authenticatable
{
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
        'role',
        'matric_no',
        'cgpa',
        'must_change_password',
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
        // Personal academic data, shown to lecturers on the screens built for
        // it and nowhere else. Hiding it from serialisation means it cannot
        // ride along in a JSON response or a Livewire payload by accident.
        'cgpa',
    ];

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
            'must_change_password' => 'boolean',
            'is_active' => 'boolean',
            'cgpa' => 'decimal:2',
        ];
    }

    /**
     * Sends the CollabSpace reset email rather than Laravel's built-in one.
     *
     * The framework's default arrives as "Hello!" from an unnamed application,
     * which for the one email a locked-out user has to trust is the wrong
     * impression entirely. Overriding here rather than swapping the notification
     * class keeps it beside the model that owns the address.
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        Mail::to($this->email)->send(new PasswordResetLink(
            $this,
            route('password.reset', ['token' => $token, 'email' => $this->email]),
            // Read from config rather than restated, so the email cannot claim
            // a lifetime the token does not have.
            (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60),
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /** Subjects this user teaches (lecturers). */
    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class, 'lecturer_id');
    }

    /** Groups this user belongs to (students). */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'group_members')
            ->using(GroupMember::class)
            ->withPivot('joined_at')
            ->as('membership');
    }

    /** The membership rows themselves, when joined_at matters. */
    public function groupMemberships(): HasMany
    {
        return $this->hasMany(GroupMember::class);
    }

    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assigned_to');
    }

    public function createdTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'created_by');
    }

    public function updatedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'updated_by');
    }

    public function createdDocuments(): HasMany
    {
        return $this->hasMany(Document::class, 'created_by');
    }

    public function editedDocuments(): HasMany
    {
        return $this->hasMany(Document::class, 'last_edited_by');
    }

    public function documentVersions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class, 'edited_by');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function appNotifications(): HasMany
    {
        return $this->hasMany(AppNotification::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Roles
    |--------------------------------------------------------------------------
    */

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isLecturer(): bool
    {
        return $this->role === 'lecturer';
    }

    public function isStudent(): bool
    {
        return $this->role === 'student';
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function scopeRole(Builder $query, string ...$roles): Builder
    {
        return $query->whereIn('role', $roles);
    }

    /** Contribution score inside one group, summing the stored weights. */
    public function contributionScoreFor(Group|int $group): int
    {
        $groupId = $group instanceof Group ? $group->getKey() : $group;

        return (int) $this->activityLogs()
            ->where('group_id', $groupId)
            ->sum('weight');
    }
}
