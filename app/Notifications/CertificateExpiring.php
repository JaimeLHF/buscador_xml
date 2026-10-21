<?php

namespace App\Notifications;

use App\Models\Certificate;
use App\Support\Fmt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CertificateExpiring extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Certificate $certificate, public int $days) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $company = $this->certificate->company;

        return (new MailMessage)
            ->subject("Certificado de {$company->razao_social} vence em {$this->days} dias")
            ->line("O certificado A1 da empresa {$company->razao_social} (".Fmt::cnpj($company->cnpj).') vence em '.Fmt::date($this->certificate->valid_to).'.')
            ->line('Depois do vencimento a busca de XMLs para esta empresa é interrompida.')
            ->action('Enviar novo certificado', route('companies.show', $company));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $company = $this->certificate->company;

        return [
            'type' => 'certificate_expiring',
            'level' => $this->days <= 7 ? 'danger' : 'warning',
            'message' => "Certificado de {$company->razao_social} vence em {$this->days} dias (".Fmt::date($this->certificate->valid_to).').',
            'url' => route('companies.show', $company, absolute: false),
            'organization_id' => $company->organization_id,
        ];
    }
}
