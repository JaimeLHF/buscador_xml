<?php

namespace App\Http\Controllers\Api;

use App\Enums\ChaveHistoricaStatus;
use App\Enums\ConferenciaSped;
use App\Enums\DocumentoTipo;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\ChaveHistorica;
use App\Models\DfeDocument;
use App\Support\Cnpj;
use App\Support\Fmt;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Monitoramento da cobertura de uma empresa, mês a mês: chaves do SPED por status, NF-e de entrada com XML
 * completo (SEFAZ ou Meu Danfe), conferência das remontadas e notas recebidas que o SPED não escriturou.
 *
 * O mês é o da emissão no horário de Brasília. Chave do SPED sem nota usa a data do C100 e, sem ela,
 * o AAMM da própria chave (contado em mes_pela_chave). Cada chave cai em um único status.
 */
class CoberturaController extends Controller
{
    private const MAX_MESES = 36;

    public function __invoke(Request $request): JsonResponse
    {
        /** @var ApiToken $token */
        $token = $request->attributes->get('api_token');

        $request->validate([
            // Conexão de uma empresa já diz qual é a empresa: o CNPJ fica opcional.
            'empresa_cnpj' => DocumentController::cnpjRules(obrigatorio: $token->company_id === null),
            'de' => ['required', 'date_format:Y-m'],
            'ate' => ['required', 'date_format:Y-m', 'after_or_equal:de'],
        ]);

        $de = CarbonImmutable::createFromFormat('!Y-m', $request->string('de')->value());
        $ate = CarbonImmutable::createFromFormat('!Y-m', $request->string('ate')->value());
        abort_if($de->diffInMonths($ate) >= self::MAX_MESES, 422, 'Intervalo máximo de '.self::MAX_MESES.' meses.');

        $cnpj = $request->filled('empresa_cnpj') ? Cnpj::normalize($request->string('empresa_cnpj')->value()) : (string) $token->company?->cnpj;

        // Só entre as empresas que a chave enxerga: CNPJ de fora devolve lista vazia.
        $companyIds = $token->companies()->where('cnpj', $cnpj)->pluck('id');

        if ($companyIds->isEmpty()) {
            return response()->json(['empresa_cnpj' => $cnpj, 'data' => []]);
        }

        $meses = [];
        for ($m = $de; $m->lessThanOrEqualTo($ate); $m = $m->addMonth()) {
            $meses[$m->format('Y-m')] = self::bloco($m->format('Y-m'));
        }

        $sped = ChaveHistorica::query()->whereIn('company_id', $companyIds)->toBase()
            ->get(['company_id', 'chave', 'status', 'sped_data'])
            ->keyBy(fn (object $c) => $c->company_id.':'.$c->chave);

        // Mês de cada nota (qualquer NF-e da empresa, resumo ou completa), para posicionar as chaves do SPED.
        $mesDaNota = [];

        DfeDocument::query()->whereIn('company_id', $companyIds)->where('tipo', DocumentoTipo::Nfe)->toBase()
            ->select(['id', 'company_id', 'chave', 'data_emissao', 'emitente_cnpj', 'tem_xml_completo', 'xml_reconstruido', 'conferencia_sped'])
            ->lazyById(1000)
            ->each(function (object $d) use (&$mesDaNota, &$meses, $sped, $cnpj): void {
                $chaveSped = $d->company_id.':'.$d->chave;
                $mes = $d->data_emissao !== null ? self::mesDaEmissao($d->data_emissao) : null;

                if ($mes !== null) {
                    $mesDaNota[$chaveSped] = $mes;
                }

                $mes ??= self::mesDaChave($d->chave);

                // Entrada é a nota que a empresa não emitiu (a mesma regra de DfeDocument::emitidaPelaEmpresa()).
                if (! $d->tem_xml_completo || $d->emitente_cnpj === $cnpj || ! isset($meses[$mes])) {
                    return;
                }

                if ($d->xml_reconstruido) {
                    $meses[$mes]['notas_entrada']['reconstruidas']++;
                    $meses[$mes]['conferencia'][match ($d->conferencia_sped) {
                        ConferenciaSped::Confere->value => 'confere',
                        ConferenciaSped::Diverge->value => 'diverge',
                        default => 'sem_conferencia',
                    }]++;
                } else {
                    $meses[$mes]['notas_entrada']['sefaz']++;
                }

                $meses[$mes]['notas_entrada']['total']++;

                if (! $sped->has($chaveSped)) {
                    $meses[$mes]['fora_do_sped']++;
                }
            });

        foreach ($sped as $chaveSped => $c) {
            $pelaChave = false;
            $mes = $mesDaNota[$chaveSped] ?? ($c->sped_data !== null ? substr((string) $c->sped_data, 0, 7) : null);

            if ($mes === null) {
                $mes = self::mesDaChave($c->chave);
                $pelaChave = true;
            }

            if (! isset($meses[$mes])) {
                continue;
            }

            // Status desconhecido vira "erro": a soma dos status sempre fecha com o total.
            $status = ChaveHistoricaStatus::tryFrom($c->status) ?? ChaveHistoricaStatus::Erro;
            $meses[$mes]['chaves_sped'][$status->value]++;
            $meses[$mes]['chaves_sped']['total']++;
            $meses[$mes]['chaves_sped']['mes_pela_chave'] += (int) $pelaChave;
        }

        return response()->json(['empresa_cnpj' => $cnpj, 'data' => array_values($meses)]);
    }

    /** @return array<string, mixed> */
    private static function bloco(string $mes): array
    {
        return [
            'mes' => $mes,
            'chaves_sped' => ['total' => 0, ...array_fill_keys(array_column(ChaveHistoricaStatus::cases(), 'value'), 0), 'mes_pela_chave' => 0],
            'notas_entrada' => ['total' => 0, 'sefaz' => 0, 'reconstruidas' => 0],
            'conferencia' => ['confere' => 0, 'diverge' => 0, 'sem_conferencia' => 0],
            'fora_do_sped' => 0,
        ];
    }

    private static function mesDaEmissao(string $utc): string
    {
        return CarbonImmutable::parse($utc, 'UTC')->timezone(Fmt::TIMEZONE)->format('Y-m');
    }

    /** AAMM da emissão gravado nas posições 3 a 6 da chave de acesso. */
    private static function mesDaChave(string $chave): string
    {
        return '20'.substr($chave, 2, 2).'-'.substr($chave, 4, 2);
    }
}
