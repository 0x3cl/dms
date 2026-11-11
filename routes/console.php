<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Clear out long-trashed files and superseded document uploads.
 *
 * Weekly rather than nightly: the point is to stop storage growing without
 * bound, not to erase things promptly, and a longer gap means a mistake has
 * longer to be noticed. The command keeps anything a document, a share or a
 * live share link still points at, whatever its age.
 *
 * Nothing is queued in this application, so this needs a real cron entry
 * running `php artisan schedule:run` for it to fire at all.
 */
Schedule::command('files:purge --days=30 --orphans')
    ->weeklyOn(0, '02:00')
    ->withoutOverlapping();
