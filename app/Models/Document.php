<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Document extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'file_path',
        'code',              
        'qr_code_path',
        'user_id',
        'type',
        'status',
        'visibility', // <-- Added this
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        // This is no longer needed if you are using the 'status' column.
        // 'is_ended' => 'boolean', 
    ];

    /**
     * The user who uploaded the document.
     */
    public function user() {
        return $this->belongsTo(User::class);
    }

    /**
     * NEW: The users who are recipients of this document.
     */
    public function recipients()
    {
        return $this->belongsToMany(User::class, 'document_recipients');
    }

    /**
     * The download history for the document.
     */
    public function downloads()
    {
        return $this->hasMany(DocumentDownload::class);
    }

    /**
     * The full tracking log for the document.
     */
    public function logs()
    {
        return $this->hasMany(DocumentLog::class);
    }

    /**
     * The most recent log entry for the document.
     */
    public function latestLog()
    {
        return $this->hasOne(DocumentLog::class)->latestOfMany();
    }

    /**
     * An accessor to get the status from the latest log.
     * Note: This is separate from the 'status' column on the document itself.
     */
    public function getCurrentStatusAttribute()
    {
        return $this->latestLog?->status ?? 'unknown';
    }
}
