<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('sefaz:dispatch-due')->everyMinute()->withoutOverlapping();

Schedule::command('alerts:certificates')->dailyAt('08:00')->timezone('America/Sao_Paulo');

Schedule::command('exports:prune')->daily();

Schedule::command('horizon:snapshot')->everyFiveMinutes();
