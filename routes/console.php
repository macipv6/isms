<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment('Build verifiable security, not checkbox security.');
})->purpose('Display an inspiring quote');

Schedule::command('register-imports:purge')->daily();
