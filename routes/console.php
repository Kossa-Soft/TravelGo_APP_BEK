<?php

use App\Models\MobileSession;
use App\Models\PhoneVerification;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('model:prune', ['--model' => [PhoneVerification::class, MobileSession::class]])->daily();

Schedule::command('travelgo:publish-due')->everyMinute()->withoutOverlapping(5);
