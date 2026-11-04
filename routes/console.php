<?php

use Illuminate\Console\Command;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('app:seed-if-empty', function (): int {
    if (DB::table('users')->exists()) {
        $this->components->info('Database already has data, skipping seeding.');

        return Command::SUCCESS;
    }

    return $this->call('db:seed', ['--force' => true]);
})->purpose('Seed the database only when it has no data yet');
