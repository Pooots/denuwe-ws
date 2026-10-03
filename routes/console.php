<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('media:push', function () {
    $local = Storage::disk('public');
    $media = Storage::disk('media');

    if (config('filesystems.disks.media.driver') === 'local') {
        $this->warn('The media disk is local storage (DO_SPACES_BUCKET is empty), so there is nothing to push.');

        return;
    }

    $copied = $skipped = 0;
    foreach ($local->allFiles() as $path) {
        if (str_starts_with(basename($path), '.')) {
            continue;
        }
        if ($media->exists($path)) {
            $skipped++;

            continue;
        }
        $media->writeStream($path, $local->readStream($path), ['visibility' => 'public']);
        $this->line("Uploaded {$path}");
        $copied++;
    }

    $this->info("Done: {$copied} uploaded, {$skipped} already there.");
})->purpose('Copy uploaded images from local storage to the media disk (DigitalOcean Spaces)');
