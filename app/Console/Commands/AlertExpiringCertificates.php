<?php

namespace App\Console\Commands;

use App\Models\Certificate;
use App\Notifications\CertificateExpiring;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

#[Signature('alerts:certificates')]
#[Description('Avisa os administradores sobre certificados que vencem em 30, 15 ou 7 dias')]
class AlertExpiringCertificates extends Command
{
    private const DAYS = [30, 15, 7];

    public function handle(): int
    {
        Certificate::query()
            ->with('company.organization')
            ->where('active', true)
            ->whereBetween('valid_to', [now(), now()->addDays(max(self::DAYS) + 1)])
            ->each(function (Certificate $certificate): void {
                $days = $certificate->daysUntilExpiration();

                // A chave evita aviso duplicado se o comando rodar mais de uma vez no mesmo marco.
                if (! in_array($days, self::DAYS, true) || ! Cache::add("alert:cert:{$certificate->id}:{$days}", true, now()->addDays(2))) {
                    return;
                }

                Notification::send($certificate->company->organization->managers(), new CertificateExpiring($certificate, $days));
            });

        return self::SUCCESS;
    }
}
