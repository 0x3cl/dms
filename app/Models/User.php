<?php

namespace App\Models;

use App\Enums\DocumentAction;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Sign-in is by `employee_id`, not email (see LoginRequest). `email` is
 * nullable and exists only so the password-reset broker has something to look
 * a user up by.
 *
 * The `division_name` / `section_name` string columns are gone: they were an
 * unmaintained second copy of what `division_id` / `section_id` already say,
 * and a rename updated one of the two. Where a historical office name matters
 * it is snapshotted onto the audit row (`document_logs.division_name`), not
 * carried on the account.
 *
 * Access is role-based (spatie/laravel-permission). A user holds exactly one
 * role; permissions are named "{module}.{action}" and the whole vocabulary
 * lives in App\Support\Modules. SUPERADMIN is short-circuited to allowed by
 * the `Gate::before` hook in AppServiceProvider, so it is never enumerated.
 *
 * A direct per-user grant (spatie's `model_has_permissions`) adds to the
 * role's set and never subtracts from it — see documentation/modules/roles.md.
 */
class User extends Authenticatable
{
    use HasFactory, HasPublicId, HasRoles, Notifiable, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'employee_id',
        'first_name',
        'last_name',
        'name',
        'email',
        'division_id',
        'section_id',
        'profile_image',
        'avatar_seed',
        'password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    /**
     * @return BelongsTo<Division, $this>
     */
    /**
     * Accounts still bind by primary key.
     *
     * HasPublicId is here for the `public_id` column — every file URL carries
     * its owner's — not to change how a `{user}` route parameter resolves.
     * Switching it would silently repoint any existing route that binds an
     * account, so the opaque identifier is used where it is needed (file URLs,
     * cross-user browsing) and named explicitly.
     */
    public function getRouteKeyName(): string
    {
        return 'id';
    }

    /**
     * Keeps `name` equal to the two halves it is assembled from.
     *
     * `name` is a stored column rather than an accessor because it is read
     * from far outside this model: every audit snapshot takes a copy of it
     * (`document_logs.actor_name`, `document_downloads.user_name`), every
     * folder and file records its owner's (`files.owner_name`), and the
     * download register searches it in SQL. Those readers must keep working
     * without knowing that a name has parts, and a query cannot call an
     * accessor.
     *
     * So this is the one place the two are reconciled, on `saving` rather than
     * in a controller — an importer, a console command and a seeder all write
     * accounts too, and none of them should have to remember.
     */
    protected static function booted(): void
    {
        static::saving(function (self $user): void {
            if ($user->isDirty(['first_name', 'last_name'])) {
                $user->name = $user->fullName();
            }
        });
    }

    /**
     * The two halves joined, which is what `name` holds.
     */
    public function fullName(): string
    {
        return trim(trim((string) $this->first_name).' '.trim((string) $this->last_name));
    }

    /**
     * The Users screen's search.
     *
     * `name` is searched alongside the two halves it is built from, so a query
     * spanning both ("Dela Cruz") matches even though neither column holds it
     * whole.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeMatching(Builder $query, string $term): Builder
    {
        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $query) use ($term) {
            $query->where('first_name', 'like', "%{$term}%")
                ->orWhere('last_name', 'like', "%{$term}%")
                ->orWhere('name', 'like', "%{$term}%")
                ->orWhere('employee_id', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%");
        });
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    /**
     * @return BelongsTo<Section, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /**
     * Documents this user uploaded.
     *
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * Documents shared with this user as a named recipient of a private upload.
     *
     * @return BelongsToMany<Document, $this>
     */
    public function receivedDocuments(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'document_recipients')
            ->withPivot('notified_at', 'revoked_at', 'revoked_by')
            // A viewer who has been taken off keeps their row, because the
            // removal is part of the record — but the tab is "what can I
            // open", so it reads the live list. See Document::recipients().
            ->wherePivotNull('revoked_at')
            ->withTimestamps();
    }

    /**
     * Trail entries this user is the actor on.
     *
     * @return HasMany<DocumentLog, $this>
     */
    public function documentLogs(): HasMany
    {
        return $this->hasMany(DocumentLog::class, 'actor_id');
    }

    /**
     * @return HasMany<DocumentDownload, $this>
     */
    public function documentDownloads(): HasMany
    {
        return $this->hasMany(DocumentDownload::class);
    }

    /**
     * The ids of documents this user has taken delivery of — one query, run by
     * the dashboard for both its count and its recent list.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    public function deliveredDocumentIds(): \Illuminate\Support\Collection
    {
        return $this->documentLogs()
            ->whereIn('action', DocumentAction::deliveryValues())
            ->distinct()
            ->pluck('document_id');
    }

    /**
     * The channel this account's notifications are broadcast on.
     *
     * Laravel's default is `App.Models.User.{id}`. Named plainly here so
     * `routes/channels.php` reads as a list of this app's channels rather
     * than one framework-shaped name among three of ours — and so the
     * authorization callback and the subscriber cannot drift apart over a
     * namespace change.
     */
    public function receivesBroadcastNotificationsOn(): string
    {
        return 'users.'.$this->id;
    }
}
