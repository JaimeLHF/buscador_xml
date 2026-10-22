<?php

namespace App\Jobs;

use App\Enums\ExportStatus;
use App\Models\Export;
use App\Queries\DocumentFilter;
use App\Services\Sefaz\XmlStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/** Monta o ZIP com os XMLs filtrados (completo quando houver, senão o resumo), em lotes para não estourar memória. */
class BuildXmlExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public Export $export) {}

    public function handle(XmlStorage $storage): void
    {
        $this->export->update(['status' => ExportStatus::Processing]);

        $tmp = tempnam(sys_get_temp_dir(), 'export');
        $zip = new ZipArchive;

        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Não foi possível criar o arquivo ZIP.');
        }

        $total = 0;

        DocumentFilter::apply($this->export->organization, $this->export->filters)
            ->with('company:id,cnpj')
            ->select(['id', 'company_id', 'chave', 'schema', 'xml_path', 'resumo_xml_path', 'tem_xml_completo'])
            ->lazyById(500)
            ->each(function ($document) use ($zip, $storage, &$total): void {
                $path = $document->xml_path ?? $document->resumo_xml_path;
                $content = $path ? $storage->get($path) : null;

                if ($content === null) {
                    return;
                }

                $suffix = $document->tem_xml_completo ? $document->schema->value : 'resNFe';
                $zip->addFromString("{$document->company->cnpj}/{$document->chave}-{$suffix}.xml", $content);
                $total++;
            });

        $zip->close();

        $path = "exports/{$this->export->organization_id}/{$this->export->id}.zip";

        if ($total > 0) {
            $stream = fopen($tmp, 'r') ?: throw new RuntimeException('Não foi possível ler o ZIP gerado.');
            Storage::disk(config('sefaz.xml_disk'))->writeStream($path, $stream);
            fclose($stream);
        }

        @unlink($tmp);

        $this->export->update([
            'status' => ExportStatus::Done,
            'total' => $total,
            'path' => $total > 0 ? $path : null,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $this->export->update(['status' => ExportStatus::Failed, 'error' => 'Falha ao gerar o arquivo. Tente novamente.']);
    }
}
