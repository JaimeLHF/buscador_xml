<?php

namespace Tests\Support;

use App\Enums\DocumentoTipo;
use App\Enums\TipoEvento;
use App\Models\Company;
use App\Services\Sefaz\EventoResponse;
use App\Services\Sefaz\SefazGateway;
use RuntimeException;
use Throwable;

/** Substitui a SEFAZ nos testes: devolve respostas enfileiradas e registra cada chamada. */
final class FakeSefazGateway implements SefazGateway
{
    /** @var list<string|Throwable> */
    private array $responses = [];

    /** @var list<array{method: string, arg: string}> */
    public array $calls = [];

    public function push(string|Throwable ...$responses): self
    {
        array_push($this->responses, ...$responses);

        return $this;
    }

    public function distNsu(Company $company, string $ultNsu, DocumentoTipo $tipo = DocumentoTipo::Nfe): string
    {
        return $this->respond($tipo === DocumentoTipo::Cte ? 'distNsuCte' : 'distNsu', $ultNsu);
    }

    public function consNsu(Company $company, string $nsu): string
    {
        return $this->respond('consNsu', $nsu);
    }

    public function consChave(Company $company, string $chave): string
    {
        return $this->respond('consChave', $chave);
    }

    public function manifestar(Company $company, string $chave, TipoEvento $tipo, int $nSeqEvento, string $xJust = ''): EventoResponse
    {
        $response = $this->respond('manifestar', "{$tipo->value}:{$chave}:{$nSeqEvento}");

        $request = $tipo->documentoTipo() === DocumentoTipo::Cte
            ? SefazFixtures::eventoCTe($company->cnpj, $chave, $tipo->value, $nSeqEvento)
            : SefazFixtures::envEvento($company->cnpj, $chave, $tipo->value, $nSeqEvento);

        return new EventoResponse($request, $response);
    }

    private function respond(string $method, string $arg): string
    {
        $this->calls[] = ['method' => $method, 'arg' => $arg];

        $response = array_shift($this->responses) ?? throw new RuntimeException("Chamada inesperada à SEFAZ: {$method}({$arg})");

        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response;
    }
}
