<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentLog extends Model
{
    protected $fillable = [
        'document_id',
        'user_id',
        'division_id',
        'section_id',
        'action',
        'remarks', 
        'received_at',
    ];

    protected $dates = ['received_at'];

    /**
     * Get the document associated with the log.
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Get the user who logged the activity.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the division where the document was logged.
     */
    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    /**
     * Get the section where the document was logged.
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }
}
