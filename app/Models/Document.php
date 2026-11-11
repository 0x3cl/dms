<?php

namespace App\Models;

use App\Enums\DocumentVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visibility' => DocumentVisibility::class,
        ];
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
     * The users a private document was shared with at upload time.
     *
     * @return BelongsToMany<User, $this>
     */
    public function recipients(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'document_recipients')
            ->withPivot('notified_at')
            ->withTimestamps();
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
     * Title-or-code search, which is what every document list offers.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeMatching(Builder $query, string $term): Builder
    {
        return $query->when($term !== '', fn (Builder $query) => $query->where(
            fn (Builder $query) => $query
                ->where('title', 'like', "%{$term}%")
                ->orWhere('code', 'like', "%{$term}%")
        ));
    }
}
