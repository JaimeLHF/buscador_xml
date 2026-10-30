<?php

use App\Models\ApiTokenAcesso;
use Illuminate\Support\Facades\Schedule;

Schedule::command('sefaz:dispatch-due')->everyMinute()->withoutOverlapping();

Schedule::command('alerts:certificates')->dailyAt('08:00')->timezone('America/Sao_Paulo');

Schedule::command('exports:prune')->daily();
// Registro de chamadas da API (ApiTokenAcesso) mais velho que o prazo de guarda.
Schedule::command('model:prune', ['--model' => [ApiTokenAcesso::class]])->daily();

Schedule::command('horizon:snapshot')->everyFiveMinutes();

// Notas antigas do SPED: para sozinho quando não há chave pendente, API configurada ou saldo.
Schedule::command('historico:buscar')->hourly()->withoutOverlapping(120)->runInBackground();
