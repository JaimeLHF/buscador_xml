<?php

namespace App\Services\MeuDanfe;

use App\Enums\ChaveHistoricaStatus;
use App\Enums\NaturezaCompra;
use App\Jobs\BuscarChavesHistoricas;
use App\Models\Company;
use Carbon\CarbonImmutable;

/**
 * Lê o SPED EFD ICMS/IPI e registra as NF-e de entrada emitidas por terceiros. As que ainda não têm XML completo
 * esperam alguém marcá-las para a consulta no Meu Danfe, que é paga.
 */
class SpedChaves
{
    /** @return list<string> chaves dos registros C100 de entrada (IND_OPER=0), de terceiros (IND_EMIT=1), modelo 55 */
    public static function extrair(string $conteudo): array
    {
        return array_keys(self::registros($conteudo));
    }

    /** Código IBGE da UF (dois primeiros dígitos do município do 0150) para a sigla. */
    private const UF_IBGE = [
        '11' => 'RO', '12' => 'AC', '13' => 'AM', '14' => 'RR', '15' => 'PA', '16' => 'AP', '17' => 'TO',
        '21' => 'MA', '22' => 'PI', '23' => 'CE', '24' => 'RN', '25' => 'PB', '26' => 'PE', '27' => 'AL', '28' => 'SE', '29' => 'BA',
        '31' => 'MG', '32' => 'ES', '33' => 'RJ', '35' => 'SP', '41' => 'PR', '42' => 'SC', '43' => 'RS',
        '50' => 'MS', '51' => 'MT', '52' => 'GO', '53' => 'DF',
    ];

    /** Situação do documento no C100 (COD_SIT) que diz que a nota não vale: cancelada, denegada ou inutilizada. */
    public const SITUACOES_SEM_NOTA = ['02', '03', '04', '05'];

    /**
     * O que o SPED diz de cada chave: valor (VL_DOC) e emissão (DT_DOC) para a conferência com o XML remontado,
     * fornecedor (0150 pelo COD_PART), CFOP de maior valor no C190, situação (COD_SIT) e impostos do C100.
     *
     * @return array<string, array{valor: ?string, data: ?string, fornecedor_nome: ?string, fornecedor_uf: ?string, cfop: ?string, natureza: ?string, sped_situacao: ?string, sped_impostos: array<string, string>}>
     */
    public static function registros(string $conteudo): array
    {
        // O PVA grava o arquivo em Latin-1; o banco só aceita UTF-8 (os nomes têm acento).
        if (! mb_check_encoding($conteudo, 'UTF-8')) {
            $conteudo = mb_convert_encoding($conteudo, 'UTF-8', 'ISO-8859-1');
        }

        $participantes = [];
        $registros = [];
        $atual = null;
        $cfops = [];
        $maiorOperacao = [];

        foreach (preg_split('/\R/', $conteudo) ?: [] as $linha) {
            // Campo vazio no fim da linha some no explode: completa até o último usado (VL_COFINS, posição 27).
            $campos = array_pad(explode('|', $linha), 30, '');

            switch ($campos[1]) {
                case '0150':
                    $participantes[$campos[2]] = [
                        'nome' => mb_substr(trim($campos[3]), 0, 120) ?: null,
                        'uf' => self::UF_IBGE[substr($campos[8], 0, 2)] ?? null,
                    ];
                    break;

                case 'C100':
                    $atual = null;

                    if ($campos[2] === '0' && $campos[3] === '1' && $campos[5] === '55' && preg_match('/^\d{44}$/', $campos[9])) {
                        $atual = $campos[9];
                        $participante = $participantes[$campos[4]] ?? null;
                        $registros[$atual] = [
                            'valor' => self::valor($campos[12]),
                            'data' => self::data($campos[10]),
                            'fornecedor_nome' => $participante['nome'] ?? null,
                            'fornecedor_uf' => $participante['uf'] ?? null,
                            'sped_situacao' => preg_match('/^\d{2}$/', $campos[6]) ? $campos[6] : null,
                            'sped_impostos' => array_filter([
                                'icms' => self::valor($campos[22]),
                                'icms_st' => self::valor($campos[24]),
                                'ipi' => self::valor($campos[25]),
                                'pis' => self::valor($campos[26]),
                                'cofins' => self::valor($campos[27]),
                                'desconto' => self::valor($campos[14]),
                                'frete' => self::valor($campos[18]),
                            ], fn (?string $v) => $v !== null && (float) $v != 0.0),
                        ];
                    }
                    break;

                case 'C190':
                    // A nota pode ter vários CFOPs: fica o de maior valor de operação.
                    $operacao = (float) (self::valor($campos[5]) ?? 0);

                    if ($atual !== null && preg_match('/^\d{4}$/', $campos[3]) && $operacao >= ($maiorOperacao[$atual] ?? -1.0)) {
                        $maiorOperacao[$atual] = $operacao;
                        $cfops[$atual] = $campos[3];
                    }
                    break;
            }
        }

        $resultado = [];

        foreach ($registros as $chave => $r) {
            $resultado[$chave] = [...$r, 'cfop' => $cfops[$chave] ?? null, 'natureza' => NaturezaCompra::doCfop($cfops[$chave] ?? null)?->value];
        }

        return $resultado;
    }

    /** @return array{lidas: int, novas: int, com_xml: int} */
    public static function importar(Company $company, string $conteudo): array
    {
        $registros = self::registros($conteudo);
        $chaves = array_keys($registros);

        $jaTem = $company->documents()->where('tem_xml_completo', true)->whereIn('chave', $chaves)->pluck('chave')->all();
        $naFila = array_values(array_diff($chaves, $jaTem));
        $agora = now();

        $novas = 0;

        foreach (array_chunk($naFila, 500) as $lote) {
            $novas += count($lote) - $company->chavesHistoricas()->whereIn('chave', $lote)->count();

            // Chave já registrada (importada antes da conferência) ganha valor e data sem perder status nem tentativas.
            $company->chavesHistoricas()->upsert(array_map(fn (string $chave) => [
                'company_id' => $company->id,
                'chave' => $chave,
                'status' => ChaveHistoricaStatus::Importada->value,
                'tentativas' => 0,
                ...self::detalhes($registros[$chave]),
                'created_at' => $agora,
                'updated_at' => $agora,
            ], $lote), ['company_id', 'chave'], [...self::COLUNAS_SPED, 'updated_at']);
        }

        // Chave que já tem XML completo não entra na fila, mas fica registrada como encontrada: a cobertura precisa
        // de todas as chaves do SPED. Se já estava registrada, ganha valor e data para o historico:conferir.
        foreach (array_chunk($jaTem, 500) as $lote) {
            $company->chavesHistoricas()->upsert(array_map(fn (string $chave) => [
                'company_id' => $company->id,
                'chave' => $chave,
                'status' => ChaveHistoricaStatus::Encontrada->value,
                'tentativas' => 0,
                ...self::detalhes($registros[$chave]),
                'created_at' => $agora,
                'updated_at' => $agora,
            ], $lote), ['company_id', 'chave'], [...self::COLUNAS_SPED, 'updated_at']);
        }

        return ['lidas' => count($chaves), 'novas' => $novas, 'com_xml' => count($jaTem)];
    }

    /**
     * Põe na fila do Meu Danfe as chaves marcadas que ainda podem ser consultadas e dispara a busca.
     *
     * @param  list<int>  $ids
     * @return int quantas entraram na fila
     */
    public static function enfileirar(Company $company, array $ids): int
    {
        $selecionaveis = array_values(array_map(fn (ChaveHistoricaStatus $s) => $s->value, array_filter(ChaveHistoricaStatus::cases(), fn (ChaveHistoricaStatus $s) => $s->selecionavel())));

        $n = $company->chavesHistoricas()->whereKey($ids)->whereIn('status', $selecionaveis)->consultaveis()
            ->update(['status' => ChaveHistoricaStatus::Pendente->value, 'tentativas' => 0, 'ultimo_erro' => null, 'updated_at' => now()]);

        if ($n > 0) {
            BuscarChavesHistoricas::dispatch();
        }

        return $n;
    }

    private const COLUNAS_SPED = ['sped_valor', 'sped_data', 'fornecedor_nome', 'fornecedor_uf', 'cfop', 'natureza', 'sped_situacao', 'sped_impostos'];

    /**
     * @param  array{valor: ?string, data: ?string, fornecedor_nome: ?string, fornecedor_uf: ?string, cfop: ?string, natureza: ?string, sped_situacao: ?string, sped_impostos: array<string, string>}  $r
     * @return array<string, mixed>
     */
    private static function detalhes(array $r): array
    {
        return [
            'sped_valor' => $r['valor'],
            'sped_data' => $r['data'],
            'fornecedor_nome' => $r['fornecedor_nome'],
            'fornecedor_uf' => $r['fornecedor_uf'],
            'cfop' => $r['cfop'],
            'natureza' => $r['natureza'],
            'sped_situacao' => $r['sped_situacao'],
            'sped_impostos' => $r['sped_impostos'] === [] ? null : json_encode($r['sped_impostos']),
        ];
    }

    /** VL_DOC vem com vírgula decimal e sem separador de milhar ("1271,00"). */
    private static function valor(string $campo): ?string
    {
        $valor = str_replace(',', '.', trim($campo));

        return is_numeric($valor) ? $valor : null;
    }

    /** DT_DOC vem como ddmmaaaa. */
    private static function data(string $campo): ?string
    {
        if (! preg_match('/^\d{8}$/', $campo)) {
            return null;
        }

        $data = CarbonImmutable::createFromFormat('!dmY', $campo);

        return $data?->format('dmY') === $campo ? $data->format('Y-m-d') : null;
    }
}
