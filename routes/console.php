<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Settlement holds
|--------------------------------------------------------------------------
|
| Hourly rather than daily. Stripe's holding limit is a compliance
| obligation, and the shortest of them is 10 days for Thailand, so a daily
| sweep that misses a run leaves very little margin. Hourly also means a
| seller waiting on a 7-day timer is paid within the hour rather than at
| whatever time the daily job happens to run.
|
| withoutOverlapping because a slow sweep must not have a second one start
| on top of it and release the same hold twice. onOneServer for the same
| reason across multiple app instances.
|
*/
Schedule::command('settlements:process-holds')
    ->hourly()
    ->withoutOverlapping(30)
    ->onOneServer()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/settlement-holds.log'));
