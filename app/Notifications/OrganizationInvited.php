<?php

namespace App\Notifications;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrganizationInvited extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Organization $organization, public User $inviter, public string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Convite para {$this->organization->name}")
            ->line("{$this->inviter->name} convidou você para acessar a organização {$this->organization->name} no ".config('app.name').'.')
            ->line('Se ainda não tiver conta, cadastre-se com este mesmo e-mail antes de aceitar.')
            ->action('Aceitar convite', route('invitations.accept', $this->token))
            ->line('O convite expira em 7 dias.');
    }
}
