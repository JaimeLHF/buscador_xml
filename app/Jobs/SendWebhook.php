<?php

namespace App\Jobs;

use App\Models\DfeDocument;
use App\Models\Organization;
use App\Services\Webhooks\WebhookUrl;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Avisa o ERP da organização que um documento chegou ou mudou. O corpo é assinado com HMAC-SHA256
 * (header X-Buscador-Assinatura) usando o segredo do webhook; quem recebe deve conferir a assinatura.
 */
class SendWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public Organization $organization, public ?DfeDocument $document, public string $evento)
    {
        $this->onQueue(config('webhooks.queue'));
    }

    public function handle(): void
    {
        $org = $this->organization->refresh();

        if (! $org->webhook_url || ! $org->webhook_secret) {
            return;
        }

        try {
            $target = WebhookUrl::resolve($org->webhook_url);
        } catch (InvalidArgumentException $e) {
            $this->registrar($org, $e->getMessage());

            return;
        }

        $body = json_encode([
            'evento' => $this->evento,
            'enviado_em' => now()->toIso8601String(),
            'documento' => $this->document ? [
                'chave' => $this->document->chave,
                'tipo' => $this->document->tipo->value,
                'empresa_cnpj' => $this->document->company->cnpj,
                'emitente_cnpj' => $this->document->emitente_cnpj,
                'emitente_nome' => $this->document->emitente_nome,
                'data_emissao' => $this->document->data_emissao?->toIso8601String(),
                'valor_total' => $this->document->valor_total,
                'situacao' => $this->document->situacao->value,
                'xml_completo' => $this->document->tem_xml_completo,
                'xml_url' => route('api.documents.xml', $this->document->chave),
            ] : null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $response = Http::timeout(10)
                ->withOptions(['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => [$target->curlResolve()]]])
                ->withHeaders([
                    'X-Buscador-Evento' => $this->evento,
                    'X-Buscador-Entrega' => (string) Str::uuid(),
                    'X-Buscador-Assinatura' => 'sha256='.hash_hmac('sha256', $body, $org->webhook_secret),
                    'User-Agent' => 'BuscadorXML-Webhook/1',
                ])
                ->withBody($body, 'application/json')
                ->post($target->url);
        } catch (Throwable $e) {
            $this->registrar($org, 'Sem resposta do servidor');

            throw new RuntimeException('Webhook sem resposta.', previous: $e);
        }

        $this->registrar($org, "HTTP {$response->status()}");

        if (! $response->successful()) {
            throw new RuntimeException("Webhook respondeu HTTP {$response->status()}.");
        }
    }

    private function registrar(Organization $org, string $status): void
    {
        $org->forceFill(['webhook_ultimo_status' => Str::limit($status, 120, ''), 'webhook_ultimo_envio_at' => now()])->saveQuietly();
    }
}
