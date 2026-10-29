<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\MeuDanfe\ConferenciaSpedService;
use App\Services\Sefaz\XmlStorage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('historico:conferir {company : ID da empresa}')]
#[Description('Confere com o SPED os XMLs do Meu Danfe já recebidos (reimporte o SPED antes, para gravar valor e data)')]
class ConferirNotasHistoricas extends Command
{
    public function handle(ConferenciaSpedService $conferencia, XmlStorage $storage): int
    {
        $company = Company::query()->whereKey((int) $this->argument('company'))->firstOrFail();
        $chaves = $company->chavesHistoricas()->get()->keyBy('chave');
        $contagem = ['confere' => 0, 'diverge' => 0, 'sem_sped' => 0, 'sem_xml' => 0];

        $company->documents()->where('xml_reconstruido', true)->each(function ($document) use ($chaves, $conferencia, $storage, &$contagem): void {
            $item = $chaves->get($document->chave);
            $xml = $document->xml_path !== null ? $storage->get($document->xml_path) : null;

            if ($xml === null) {
                $contagem['sem_xml']++;

                return;
            }

            if ($item === null) {
                $contagem['sem_sped']++;

                return;
            }

            $conferencia->conferir($document, $item, $xml);
            $contagem[$document->conferencia_sped->value ?? 'sem_sped']++;
        });

        $this->info("Confere: {$contagem['confere']} · Diverge: {$contagem['diverge']} · Sem valor/data do SPED: {$contagem['sem_sped']} · XML ausente: {$contagem['sem_xml']}");

        return self::SUCCESS;
    }
}
