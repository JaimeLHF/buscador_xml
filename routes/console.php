<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('sefaz:dispatch-due')->everyMinute()->withoutOverlapping();

Schedule::command('horizon:snapshot')->everyFiveMinutes();
