<?php

namespace App\Enums;

/**
 * Everything the activity log records.
 *
 * The stored value is a slug and the sentence is `label()`, the same shape as
 * App\Enums\DocumentAction — so the wording on screen can be corrected without
 * rewriting history, and a mistyped comparison is a type error rather than a
 * check that quietly never matches. The dashboard's "ended" count was
 * permanently zero for exactly that reason once.
 */
enum ActivityAction: string
{
    case Uploaded = 'uploaded';
    /*
     * A file's bytes were superseded, or an earlier version brought back.
     *
     * Both belong here rather than in `document_logs`, with one exception
     * argued in App\Services\Files\FileEventRecorder: a *tracked* document's
     * replacement also writes `DocumentAction::FileReplaced`, because for a
     * tracked document the file is the document. A revert does not get a
     * document action of its own — to a reader it is a replacement, and
     * "these bytes are a copy of version 1" is storage mechanics.
     */
    case VersionReplaced = 'version_replaced';
    case VersionReverted = 'version_reverted';
    case Previewed = 'previewed';
    case Downloaded = 'downloaded';
    case Renamed = 'renamed';
    case Moved = 'moved';
    case Copied = 'copied';
    case Deleted = 'deleted';
    case Restored = 'restored';
    case Purged = 'purged';
    case FolderCreated = 'folder_created';
    case Zipped = 'zipped';
    case Extracted = 'extracted';
    case Shared = 'shared';
    case ShareRevoked = 'share_revoked';
    case LinkCreated = 'link_created';
    case LinkUpdated = 'link_updated';
    case LinkRegenerated = 'link_regenerated';
    case LinkRevoked = 'link_revoked';
    case LinkAccessed = 'link_accessed';
    case VisibilityChanged = 'visibility_changed';
    case OwnershipTransferred = 'ownership_transferred';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'Uploaded',
            self::VersionReplaced => 'Replaced the file',
            self::VersionReverted => 'Restored an earlier version',
            self::Previewed => 'Previewed',
            self::Downloaded => 'Downloaded',
            self::Renamed => 'Renamed',
            self::Moved => 'Moved',
            self::Copied => 'Copied',
            self::Deleted => 'Deleted',
            self::Restored => 'Restored',
            self::Purged => 'Permanently deleted',
            self::FolderCreated => 'Created folder',
            self::Zipped => 'Downloaded as archive',
            self::Extracted => 'Extracted archive',
            self::Shared => 'Shared',
            self::ShareRevoked => 'Revoked a share',
            self::LinkCreated => 'Created a share link',
            self::LinkUpdated => 'Changed a share link',
            self::LinkRegenerated => 'Replaced a share link\'s address',
            self::LinkRevoked => 'Revoked a share link',
            self::LinkAccessed => 'Opened a share link',
            self::VisibilityChanged => 'Changed privacy',
            self::OwnershipTransferred => 'Transferred ownership',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Uploaded => 'fa-solid fa-cloud-arrow-up',
            self::VersionReplaced => 'fa-solid fa-arrow-up-from-bracket',
            self::VersionReverted => 'fa-solid fa-clock-rotate-left',
            self::Previewed => 'fa-solid fa-eye',
            self::Downloaded, self::Zipped => 'fa-solid fa-download',
            self::Renamed => 'fa-solid fa-pen',
            self::Moved => 'fa-solid fa-arrow-right-arrow-left',
            self::Copied => 'fa-solid fa-copy',
            self::Deleted => 'fa-solid fa-trash',
            self::Restored => 'fa-solid fa-rotate-left',
            self::Purged => 'fa-solid fa-fire',
            self::FolderCreated => 'fa-solid fa-folder-plus',
            self::Extracted => 'fa-solid fa-box-open',
            self::Shared, self::LinkCreated => 'fa-solid fa-share-nodes',
            self::LinkUpdated, self::LinkRegenerated => 'fa-solid fa-link',
            self::ShareRevoked, self::LinkRevoked => 'fa-solid fa-user-slash',
            self::LinkAccessed => 'fa-solid fa-link',
            self::VisibilityChanged => 'fa-solid fa-lock',
            self::OwnershipTransferred => 'fa-solid fa-user-pen',
        };
    }

    /**
     * The actions that mean a copy of something left the server.
     *
     * @return list<self>
     */
    public static function releases(): array
    {
        return [self::Downloaded, self::Zipped, self::Copied];
    }

    /**
     * The actions that change which bytes a file holds.
     *
     * @return list<self>
     */
    public static function versioning(): array
    {
        return [self::VersionReplaced, self::VersionReverted];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return list<array{value: string, label: string, icon: string}> */
    public static function options(): array
    {
        return array_map(fn (self $action): array => [
            'value' => $action->value,
            'label' => $action->label(),
            'icon' => $action->icon(),
        ], self::cases());
    }
}
