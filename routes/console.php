<?php

use App\Services\Examinations\CandidateAttemptService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('examinations:expire', function () {
    $count = app(CandidateAttemptService::class)->expireDue();
    $this->info("Reconciled {$count} expired attempts.");
})->purpose('Submit or expire attempts whose server deadline has passed');

Schedule::command('examinations:expire')->everyMinute()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
