<?php

namespace App\Services\MeuDanfe;

use App\Enums\ChaveHistoricaStatus;
use App\Models\Company;
use Carbon\CarbonImmutable;

/** Lê o SPED EFD ICMS/IPI e põe na fila as NF-e de entrada emitidas por terceiros que ainda não têm XML completo. */
class SpedChaves
{
    /** @return list<string> chaves dos registros C100 de entrada (IND_OPER=0), de terceiros (IND_EMIT=1), modelo 55 */
    public static function extrair(string $conteudo): array
    {
        return array_keys(self::registros($conteudo));
    }

    /**
     * Valor (VL_DOC) e emissão (DT_DOC) de cada chave, guardados para a conferência com o XML do Meu Danfe.
     *
     * @return array<string, array{valor: ?string, data: ?string}>
     */
    public static function registros(string $conteudo): array
    {
        $registros = [];

        foreach (preg_split('/\R/', $conteudo) as $linha) {
            if (! str_starts_with($linha, '|C100|')) {
                continue;
            }

            $campos = explode('|', $linha);

            if (($campos[2] ?? '') === '0' && ($campos[3] ?? '') === '1' && ($campos[5] ?? '') === '55' && preg_match('/^\d{44}$/', $campos[9] ?? '')) {
                $registros[$campos[9]] = ['valor' => self::valor($campos[12] ?? ''), 'data' => self::data($campos[10] ?? '')];
            }
        }

        return $registros;
    }

    /** @return array{lidas: int, novas: int} */
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

            // Chave que já estava na fila (importada antes da conferência) ganha valor e data sem perder status nem tentativas.
            $company->chavesHistoricas()->upsert(array_map(fn (string $chave) => [
                'company_id' => $company->id,
                'chave' => $chave,
                'status' => ChaveHistoricaStatus::Pendente->value,
                'tentativas' => 0,
                'sped_valor' => $registros[$chave]['valor'],
                'sped_data' => $registros[$chave]['data'],
                'created_at' => $agora,
                'updated_at' => $agora,
            ], $lote), ['company_id', 'chave'], ['sped_valor', 'sped_data', 'updated_at']);
        }

        // Nota que já veio do Meu Danfe não volta para a fila, mas ganha valor e data para o historico:conferir.
        foreach (array_chunk($jaTem, 500) as $lote) {
            foreach ($company->chavesHistoricas()->whereIn('chave', $lote)->get() as $item) {
                $item->update(['sped_valor' => $registros[$item->chave]['valor'], 'sped_data' => $registros[$item->chave]['data']]);
            }
        }

        return ['lidas' => count($chaves), 'novas' => $novas];
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
