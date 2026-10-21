<?php

namespace App\Notifications;

use App\Models\Company;
use App\Support\Fmt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CompanyBlocked extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Company $company, public int $occurrences) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Consumo indevido repetido: {$this->company->razao_social}")
            ->line("A SEFAZ recusou {$this->occurrences} consultas seguidas da empresa {$this->company->razao_social} (".Fmt::cnpj($this->company->cnpj).') por consumo indevido (656).')
            ->line('As consultas continuam pausadas de 1 em 1 hora. Verifique se outro sistema está consultando este CNPJ com o mesmo certificado.')
            ->action('Ver empresa', route('companies.show', $this->company));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'company_blocked',
            'level' => 'danger',
            'message' => "{$this->company->razao_social}: {$this->occurrences} bloqueios seguidos por consumo indevido (656).",
            'url' => route('companies.show', $this->company, absolute: false),
            'organization_id' => $this->company->organization_id,
        ];
    }
}
