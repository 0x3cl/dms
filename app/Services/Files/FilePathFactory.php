<?php

namespace App\Services\Files;

use App\Models\File;

/**
 * The only place a storage path is built.
 *
 * A path is `{first two characters of public_id}/{public_id}.{ext}` — derived
 * entirely from values this application generated. Nothing a user supplied,
 * and in particular no uploaded filename, ever reaches the filesystem.
 *
 * That is the whole defence against path traversal and filename collision, and
 * it is a structural one rather than a filter: there is no input to sanitise,
 * because there is no input. It is also why extracting a zip is safe — an
 * archive entry never chooses where its bytes land.
 *
 * The two-character prefix shards the tree. A single flat directory holding
 * hundreds of thousands of files is slow to list and, on some filesystems,
 * slow to open a file in at all.
 */
class FilePathFactory
{
    public function for(File $file): string
    {
        return $this->fromParts($file->public_id, $file->extension);
    }

    public function fromParts(string $publicId, string $extension): string
    {
        $shard = substr($publicId, 0, 2);
        $suffix = $this->normalizeExtension($extension);

        return "{$shard}/{$publicId}{$suffix}";
    }

    /** The thumbnail beside its file, under the same shard. */
    public function thumbnailFor(File $file): string
    {
        return substr($file->public_id, 0, 2).'/'.$file->public_id.'-thumb.webp';
    }

    /**
     * An extension is kept only so the stored object has a recognisable tail;
     * it is never trusted and never used to decide a content type. Anything
     * that is not plainly alphanumeric is dropped rather than escaped, because
     * a path component here has no legitimate reason to contain anything else.
     */
    private function normalizeExtension(string $extension): string
    {
        $clean = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $extension) ?? '');

        return $clean === '' ? '' : '.'.substr($clean, 0, 16);
    }
}
