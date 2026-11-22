<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A comment written on one version of a document.
 *
 * **The version is part of the comment.** "This needs the annex" said about
 * version 1 is not advice about version 3, so a thread does not silently
 * carry forward across a replacement — the panel shows the comments written
 * about the bytes on screen, and the count beside the others says how many
 * the rest of the history holds.
 *
 * **It snapshots its author**, like every other row in this application that
 * records something somebody did: `user_id` for following the row back to
 * the live account, `actor_name` for what the page renders. `user_id` is
 * `nullOnDelete`, so a comment outlives the account that wrote it still
 * saying who wrote it, rather than becoming an anonymous paragraph.
 *
 * It is **not** an audit table and deliberately not in `document_logs`. A
 * comment is content somebody wrote, editable in principle and deletable by
 * its author; the trail is a record of events that must not change. What the
 * trail holds is the *fact* that a comment was posted, which is why
 * `DocumentAction::Commented` exists and the body does not appear in it.
 */
class DocumentComment extends Model
{
    use HasFactory;

    protected $fillable = ['document_id', 'version', 'user_id', 'actor_name', 'body'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The author, from the live account when it is there and the snapshot
     * when it is not — the same shape as `Document::uploaderName()`.
     */
    public function authorName(): string
    {
        return $this->relationLoaded('user') && $this->user
            ? $this->user->name
            : ($this->actor_name ?? 'Unknown');
    }

    /**
     * Comments about one version's bytes.
     *
     * A null version matches only a null version: a comment with no version
     * is one written before the column existed or about a document whose
     * bytes are gone, and folding it into whichever version happens to be on
     * screen would attach somebody's words to bytes they never saw.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForVersion(Builder $query, ?int $version): Builder
    {
        return $version === null
            ? $query->whereNull('version')
            : $query->where('version', $version);
    }

    /**
     * Write one, snapshotting the author.
     *
     * The one place a comment row is built, so every writer produces the
     * same shape — the reason `DocumentLog::record()` exists.
     */
    public static function write(Document $document, User $author, string $body, ?int $version): self
    {
        return self::create([
            'document_id' => $document->id,
            'version' => $version,
            'user_id' => $author->id,
            'actor_name' => $author->name,
            'body' => $body,
        ]);
    }
}
