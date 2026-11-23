<?php

namespace App\Models;

use App\Enums\DocumentVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An uploaded office document.
 *
 * A document has no lifecycle state. The `status`, `ended_at`, `is_ended`,
 * `target_division_id` and `target_section_id` columns are all gone: the first
 * two with the End Action the office asked to have removed, the rest as
 * leftovers from earlier designs that nothing read or wrote. A document that
 * should no longer circulate is withdrawn by its uploader, which is a delete.
 *
 * `visibility` decides who may read it, and
 * DocumentController::isAuthorized() is the only gate.
 *
 * `document_type_id` is the live type; `type` is its name as it stood at
 * upload. Both are kept on purpose — see the migration — and
 * `uploader_name` is the same idea for the account that uploaded it, so the
 * document outlives a deleted uploader with its history intact.
 */
class Document extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'title',
        'document_type_id',
        'type',
        'file_path',
        'file_name',
        'qr_code_path',
        'file_id',
        'user_id',
        'uploader_name',
        'visibility',
        'comments_enabled',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visibility' => DocumentVisibility::class,
            'comments_enabled' => 'boolean',
        ];
    }

    /**
     * Whether a link may exist for this document, appended to every payload.
     *
     * The page needs this to decide what Copy link puts on the clipboard: a
     * tracking URL that opens without an account, or — for a confidential
     * document, which by definition has no such URL — the tracking code with a
     * sentence saying why. It is appended rather than recomputed in a template
     * for the usual reason: the rule about what may be linked lives in
     * `DocumentVisibility`, and a second statement of it in JavaScript is a
     * second thing to get wrong when a level is added.
     *
     * @var list<string>
     */
    protected $appends = ['linkable'];

    /**
     * @return Attribute<bool, never>
     */
    protected function linkable(): Attribute
    {
        return Attribute::make(get: fn (): bool => $this->visibility?->linkable() ?? false);
    }

    /**
     * The user who uploaded the document, when that account still exists.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<DocumentType, $this>
     */
    /**
     * Where this document's bytes are, however they got there.
     *
     * Prefers the `files` row. Falls back to the legacy public-disk path for
     * rows that predate the move to private storage and have not yet been
     * carried over by `files:import-documents`.
     *
     * Returning the disk alongside the path is what lets the one reader in
     * DocumentController serve both without caring which it got.
     *
     * @return array{disk: string, path: string, name: string}|null
     */
    public function storedFile(): ?array
    {
        if ($this->file) {
            return [
                'disk' => $this->file->disk,
                'path' => $this->file->storage_path,
                'name' => $this->file->downloadName(),
            ];
        }

        if ($this->file_path) {
            return [
                'disk' => 'public',
                'path' => $this->file_path,
                'name' => $this->file_name ?: basename($this->file_path),
            ];
        }

        return null;
    }

    /**
     * The stored bytes.
     *
     * A document is a tracking jacket over a `files` row: the code, the trail
     * and the recipients live here, the bytes live there.
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    /**
     * The people who may open this document right now.
     *
     * **This relation is the live list, not the whole table.** A viewer the
     * uploader has taken off keeps their row — the removal is a fact about who
     * could read what and when, and erasing the row would erase it — so every
     * reader of this relation has to exclude the revoked ones. Putting that
     * `wherePivotNull` here rather than at each call site is what stops the
     * resolver, the "Shared with me" tab, the recipient chips and the
     * notification list from each needing to remember it; one of them
     * forgetting is a removed viewer who can still read the document.
     *
     * `allRecipients()` is the complete record, and is what writes go through.
     *
     * @return BelongsToMany<User, $this>
     */
    public function recipients(): BelongsToMany
    {
        return $this->allRecipients()->wherePivotNull('revoked_at');
    }

    /**
     * Every row ever written for this document, revoked ones included.
     *
     * Tagging goes through here, and must: `syncWithoutDetaching` on the
     * filtered relation cannot see a revoked row, so it would try to *insert* a
     * second one and hit the `(document_id, user_id)` unique pair. Through this
     * relation the same call updates the existing row and clears the removal,
     * which is the right reading of re-tagging somebody.
     *
     * @return BelongsToMany<User, $this>
     */
    public function allRecipients(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'document_recipients')
            ->withPivot('notified_at', 'revoked_at', 'revoked_by')
            ->withTimestamps();
    }

    /**
     * The viewers this document's uploader has taken off, most recent first.
     *
     * @return BelongsToMany<User, $this>
     */
    public function removedViewers(): BelongsToMany
    {
        return $this->allRecipients()
            ->wherePivotNotNull('revoked_at')
            ->orderByPivot('revoked_at', 'desc');
    }

    /**
     * Was this person tagged on this document and then taken off it?
     *
     * The one question that separates the two refusals a reader can get. It is
     * deliberately *not* asked of the access rule — access is decided by the
     * live list alone, and this only decides which sentence explains a refusal
     * the resolver has already made.
     */
    public function wasRemovedAsViewer(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->removedViewers()->whereKey($user->getKey())->exists();
    }

    /**
     * Who took a copy of the file, for /admin/download-logs.
     *
     * @return HasMany<DocumentDownload, $this>
     */
    public function downloads(): HasMany
    {
        return $this->hasMany(DocumentDownload::class);
    }

    /**
     * Comments written on this document, newest first.
     *
     * Not part of the audit trail: a comment is content its author wrote,
     * while `document_logs` is a record of events that must not change. The
     * trail holds the *fact* that one was posted, as
     * `DocumentAction::Commented`, and never the body.
     *
     * @return HasMany<DocumentComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(DocumentComment::class)->latest('created_at');
    }

    /**
     * The document's travel history.
     *
     * @return HasMany<DocumentLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(DocumentLog::class);
    }

    /**
     * @return HasOne<DocumentLog, $this>
     */
    public function latestLog(): HasOne
    {
        return $this->hasOne(DocumentLog::class)->latestOfMany();
    }

    /**
     * Links that open this document without an account.
     *
     * The link is minted against the **document**, not against its `File` row:
     * the document is the thing somebody decided to share, the thing the trail
     * is written about, and the thing carrying the classification the link has
     * to obey. A link on the file would answer to the file's own visibility,
     * which is the looser second door `AccessResolver::forFile()` exists to
     * close.
     *
     * @return MorphMany<ShareLink, $this>
     */
    public function shareLinks(): MorphMany
    {
        return $this->morphMany(ShareLink::class, 'shareable');
    }

    public function isPublic(): bool
    {
        return $this->visibility === DocumentVisibility::Public;
    }

    /**
     * The uploader's name, from the live account when it is there and from the
     * snapshot when it is not.
     */
    public function uploaderName(): string
    {
        return $this->relationLoaded('user') && $this->user
            ? $this->user->name
            : ($this->uploader_name ?? 'Unknown');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeUploadedBy(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * What somebody has withdrawn: their own documents, soft-deleted.
     *
     * One statement, because two screens ask it — the Trash, which is where
     * everything soft-deleted belongs, and the register's own Deleted tab,
     * which is where somebody working through the register will look first.
     * Two hand-built `onlyTrashed()` queries is how one of them quietly
     * starts showing a document the other does not.
     *
     * Deliberately the uploader's own, administrator included: restoring
     * mirrors deleting and `DocumentController::destroy()` is uploader-only,
     * and what somebody decided to take off the register is not a thing the
     * register should go on showing to everybody.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeWithdrawnBy(Builder $query, int $userId): Builder
    {
        return $query->onlyTrashed()->uploadedBy($userId);
    }

    /**
     * Title-or-code search, which is what every document list offers.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    /**
     * Search the register by title, tracking code — or file name.
     *
     * The other half of the same complaint: somebody has the filename a
     * colleague sent them and no idea what the document was titled. The
     * filename is snapshotted on the row already, so this costs no join.
     */
    /**
     * What may be said about this document to somebody who may not read it.
     *
     * The classification and the code, and deliberately nothing else — no
     * title, no type, no uploader, no file. Being told "this is private" is
     * not a disclosure; being told what the private thing is *about* would
     * be.
     *
     * It lives here rather than in either controller because two of them
     * answer this question now: the public tracking page, for a visitor
     * holding a code, and the share-link page, for somebody holding a link
     * to a document whose level does not open for them. Two copies of a
     * disclosure boundary is how one of them quietly grows a field.
     *
     * @return array<string, mixed>
     */
    public function restrictedSummary(): array
    {
        return [
            'code' => $this->code,
            'visibility' => $this->visibility->value,
            'visibility_label' => $this->visibility->label(),
            'visibility_description' => $this->visibility->description(),
            'open' => false,
            'title' => null,
            'type' => null,
            'uploader' => null,
            'uploaded_at' => null,
            'preview' => null,
        ];
    }

    public function scopeMatching(Builder $query, string $term): Builder
    {
        return $query->when($term !== '', fn (Builder $query) => $query->where(
            fn (Builder $query) => $query
                ->where('title', 'like', "%{$term}%")
                ->orWhere('code', 'like', "%{$term}%")
                ->orWhere('file_name', 'like', "%{$term}%")
        ));
    }
}
