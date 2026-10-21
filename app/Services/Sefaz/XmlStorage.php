<?php

namespace App\Services\Sefaz;

use App\Models\Company;
use Illuminate\Support\Facades\Storage;

/** Grava o XML exatamente como recebido (sem reformatar), num disco que pode ser local ou S3. */
class XmlStorage
{
    public function putDocument(Company $company, string $tipo, string $chave, string $schema, string $xml): string
    {
        // Ano/mês da chave de acesso (posições 3–6, AAMM) mantém os diretórios estáveis e previsíveis.
        $path = sprintf('dfe/%d/%s/20%s/%s/%s-%s.xml', $company->id, $tipo, substr($chave, 2, 2), substr($chave, 4, 2), $chave, $schema);

        Storage::disk(config('sefaz.xml_disk'))->put($path, $xml);

        return $path;
    }

    public function putEvent(Company $company, string $chave, string $tpEvento, int $seq, string $schema, string $xml): string
    {
        $path = sprintf('dfe/%d/eventos/%s-%s-%02d-%s.xml', $company->id, $chave, $tpEvento, $seq, $schema);

        Storage::disk(config('sefaz.xml_disk'))->put($path, $xml);

        return $path;
    }

    public function putUnsupported(Company $company, string $nsu, string $rawSchema, string $xml): string
    {
        $schema = preg_replace('/[^A-Za-z0-9_.]/', '', $rawSchema) ?: 'desconhecido';
        $path = sprintf('dfe/%d/nao-processados/%s-%s.xml', $company->id, preg_replace('/\D/', '', $nsu), $schema);

        Storage::disk(config('sefaz.xml_disk'))->put($path, $xml);

        return $path;
    }

    public function get(string $path): ?string
    {
        return Storage::disk(config('sefaz.xml_disk'))->get($path);
    }
}
