<?php

namespace App\Services\MeuDanfe;

use App\Enums\ChaveHistoricaStatus;
use App\Enums\DfeSchema;
use App\Models\ChaveHistorica;
use App\Services\Sefaz\DocumentProcessor;
use App\Services\Sefaz\DocZip;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Busca no Meu Danfe o XML das chaves históricas pendentes e grava cada nota como se viesse da SEFAZ. */
class BuscaHistoricaService
{
    private const MAX_TENTATIVAS = 3;

    public function __construct(private MeuDanfeClient $client, private DocumentProcessor $processor, private ConferenciaSpedService $conferencia) {}

    /**
     * Lê a fila uma chave por vez, para pegar também as marcadas durante a busca. Com prazo, para antes dele
     * (o job tem tempo máximo) e as restantes ficam para a próxima rodada.
     *
     * @return array{encontradas: int, nao_encontradas: int, erros: int, sem_saldo: bool}
     */
    public function buscar(int $limite, ?CarbonImmutable $prazo = null): array
    {
        $resultado = ['encontradas' => 0, 'nao_encontradas' => 0, 'erros' => 0, 'sem_saldo' => false];
        $ultimoId = 0;

        for ($i = 0; $i < $limite && ($prazo === null || now()->lessThan($prazo)); $i++) {
            $item = ChaveHistorica::with('company.organization')
                ->where('status', ChaveHistoricaStatus::Pendente)
                ->where('id', '>', $ultimoId)
                ->orderBy('id')
                ->first();

            if ($item === null) {
                break;
            }

            $ultimoId = $item->id;

            try {
                $status = $this->aguardar($item->chave);
            } catch (SemSaldoException) {
                $resultado['sem_saldo'] = true;
                Log::warning('Meu Danfe sem saldo: busca de notas antigas pausada.');

                break;
            } catch (Throwable $e) {
                $this->falhou($item, $e->getMessage());
                $resultado['erros']++;

                continue;
            }

            if ($status === 'OK') {
                $resultado[$this->salvar($item) ? 'encontradas' : 'erros']++;
            } elseif ($status === 'NOT_FOUND') {
                $this->finalizar($item, ChaveHistoricaStatus::NaoEncontrada);
                $resultado['nao_encontradas']++;
            } else {
                $this->falhou($item, "Meu Danfe retornou {$status}.");
                $resultado['erros']++;
            }
        }

        return $resultado;
    }

    private function aguardar(string $chave): string
    {
        $intervalo = (int) config('services.meudanfe.intervalo_segundos');
        $status = $this->client->adicionar($chave);

        for ($i = 1; in_array($status, ['WAITING', 'SEARCHING'], true) && $i < config('services.meudanfe.max_consultas_por_chave'); $i++) {
            Sleep::for($intervalo)->seconds();
            $status = $this->client->adicionar($chave);
        }

        // Respeita o intervalo mínimo entre chamadas à mesma chave antes do download.
        Sleep::for($intervalo)->seconds();

        return $status;
    }

    private function salvar(ChaveHistorica $item): bool
    {
        try {
            $xml = $this->client->xml($item->chave);
            $this->validar($item->chave, $xml);

            DB::transaction(function () use ($item, $xml): void {
                // NSU zerado: a nota não veio da distribuição, como na consulta por chave (consChNFe).
                $this->processor->process($item->company, new DocZip('000000000000000', 'procNFe', DfeSchema::ProcNFe, $xml));
                $document = $item->company->documents()->where('chave', $item->chave)->sole();
                $document->update(['xml_reconstruido' => true]);
                $this->conferencia->conferir($document, $item, $xml);
                $this->finalizar($item, ChaveHistoricaStatus::Encontrada);
            });

            return true;
        } catch (Throwable $e) {
            $this->falhou($item, $e->getMessage());

            return false;
        }
    }

    /** O Meu Danfe remonta o XML sem a assinatura do emitente: exige ao menos a chave pedida e o protocolo de autorização. */
    private function validar(string $chave, string $xml): void
    {
        $dom = new DOMDocument;

        if (! @$dom->loadXML($xml, LIBXML_NONET) || $dom->getElementsByTagName('infProt')->length === 0) {
            throw new RuntimeException('XML recebido sem protocolo de autorização ou inválido.');
        }

        $infNFe = $dom->getElementsByTagName('infNFe')->item(0);

        if (! $infNFe instanceof DOMElement || $infNFe->getAttribute('Id') !== "NFe{$chave}") {
            throw new RuntimeException('XML recebido não corresponde à chave pedida.');
        }
    }

    private function finalizar(ChaveHistorica $item, ChaveHistoricaStatus $status): void
    {
        $item->update(['status' => $status, 'ultimo_erro' => null, 'buscado_em' => now()]);
    }

    private function falhou(ChaveHistorica $item, string $erro): void
    {
        $tentativas = $item->tentativas + 1;

        $item->update([
            'tentativas' => $tentativas,
            'status' => $tentativas >= self::MAX_TENTATIVAS ? ChaveHistoricaStatus::Erro : ChaveHistoricaStatus::Pendente,
            'ultimo_erro' => Str::limit($erro, 250),
            'buscado_em' => now(),
        ]);
    }
}
