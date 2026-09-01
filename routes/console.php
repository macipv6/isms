<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function (): void {
    $this->comment('Build verifiable security, not checkbox security.');
})->purpose('Display an inspiring quote');
