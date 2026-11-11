<?php

namespace Database\Seeders;

use App\Models\UploadSetting;
use Illuminate\Database\Seeder;

/**
 * The two upload policies.
 *
 * `documents` is seeded with **exactly** the limits that were hard-coded in
 * DocumentController before this module existed — `pdf,doc,docx` at 10 MB — so
 * installing this changes no behaviour on day one. A settings module that
 * silently loosens the rules the moment it lands is not a settings module.
 *
 * `files` is seeded broadly, because a personal filing cabinet restricted to
 * three document types is not a filing cabinet.
 *
 * Idempotent on `scope`, and it does **not** overwrite a policy an
 * administrator has since edited.
 */
class UploadSettingSeeder extends Seeder
{
    public function run(): void
    {
        UploadSetting::query()->firstOrCreate(
            ['scope' => UploadSetting::SCOPE_DOCUMENTS],
            [
                'min_size_kb' => 0,
                'max_size_kb' => 10240,
                'allowed_extensions' => ['pdf', 'doc', 'docx'],
                'allowed_mime_types' => [
                    'application/pdf',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                ],
                'prohibited_extensions' => UploadSetting::DANGEROUS,
                'max_files_per_upload' => 1,
                // A tracked document is one file with one tracking code, so
                // there is nothing here to extract.
                'max_archive_size_kb' => 0,
                'max_archive_entries' => 0,
                'max_extracted_kb' => 0,
                'max_archive_depth' => 1,
                'allow_nested_archives' => false,
            ],
        );

        UploadSetting::query()->firstOrCreate(
            ['scope' => UploadSetting::SCOPE_FILES],
            [
                'min_size_kb' => 0,
                'max_size_kb' => 51200,
                'allowed_extensions' => [
                    'pdf', 'doc', 'docx', 'rtf', 'odt', 'txt', 'md',
                    'xls', 'xlsx', 'csv', 'ods',
                    'ppt', 'pptx', 'odp',
                    'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'bmp', 'tif', 'tiff',
                    'zip',
                ],
                'allowed_mime_types' => [
                    'application/pdf',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/rtf',
                    'application/vnd.oasis.opendocument.text',
                    'text/plain',
                    'text/markdown',
                    'application/vnd.ms-excel',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'text/csv',
                    'application/vnd.oasis.opendocument.spreadsheet',
                    'application/vnd.ms-powerpoint',
                    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                    'application/vnd.oasis.opendocument.presentation',
                    'image/png', 'image/jpeg', 'image/gif', 'image/webp',
                    'image/svg+xml', 'image/bmp', 'image/tiff',
                    'application/zip', 'application/x-zip-compressed',
                ],
                'prohibited_extensions' => UploadSetting::DANGEROUS,
                'max_files_per_upload' => 20,
                'max_archive_size_kb' => 51200,
                'max_archive_entries' => 2000,
                'max_extracted_kb' => 204800,
                'max_archive_depth' => 8,
                'allow_nested_archives' => false,
            ],
        );
    }
}
