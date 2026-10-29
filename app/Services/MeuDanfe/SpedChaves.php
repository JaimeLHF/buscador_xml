<?php

namespace App\Services\MeuDanfe;

use App\Enums\ChaveHistoricaStatus;
use App\Models\Company;

/** Lê o SPED EFD ICMS/IPI e põe na fila as NF-e de entrada emitidas por terceiros que ainda não têm XML completo. */
class SpedChaves
{
    /** @return list<string> chaves dos registros C100 de entrada (IND_OPER=0), de terceiros (IND_EMIT=1), modelo 55 */
    public static function extrair(string $conteudo): array
    {
        $chaves = [];

        foreach (preg_split('/\R/', $conteudo) as $linha) {
            if (! str_starts_with($linha, '|C100|')) {
                continue;
            }

            $campos = explode('|', $linha);

            if (($campos[2] ?? '') === '0' && ($campos[3] ?? '') === '1' && ($campos[5] ?? '') === '55' && preg_match('/^\d{44}$/', $campos[9] ?? '')) {
                $chaves[$campos[9]] = true;
            }
        }

        return array_keys($chaves);
    }

    /** @return array{lidas: int, novas: int} */
    public static function importar(Company $company, string $conteudo): array
    {
        $chaves = self::extrair($conteudo);

        $jaTem = $company->documents()->where('tem_xml_completo', true)->whereIn('chave', $chaves)->pluck('chave')->all();
        $novas = array_values(array_diff($chaves, $jaTem));
        $agora = now();

        $inseridas = 0;

        foreach (array_chunk($novas, 500) as $lote) {
            $inseridas += $company->chavesHistoricas()->insertOrIgnore(array_map(fn (string $chave) => [
                'company_id' => $company->id,
                'chave' => $chave,
                'status' => ChaveHistoricaStatus::Pendente->value,
                'tentativas' => 0,
                'created_at' => $agora,
                'updated_at' => $agora,
            ], $lote));
        }

        return ['lidas' => count($chaves), 'novas' => $inseridas];
    }
}
