<?php

namespace App\Jobs;

use App\Enums\ExportStatus;
use App\Models\DfeDocument;
use App\Models\Export;
use App\Queries\DocumentFilter;
use App\Support\Fmt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Planilha com uma linha por documento filtrado, no formato que o Excel em português abre direto:
 * separador ";", vírgula decimal e BOM UTF-8 para os acentos.
 */
class BuildCsvReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    private const COLUNAS = ['Tipo', 'Empresa (CNPJ)', 'Emitente (CNPJ)', 'Emitente', 'Número', 'Série', 'Emissão', 'Valor', 'Situação', 'Manifestação', 'XML completo', 'Chave de acesso'];

    public function __construct(public Export $export) {}

    public function handle(): void
    {
        $this->export->update(['status' => ExportStatus::Processing]);

        $out = fopen('php://temp', 'w+') ?: throw new RuntimeException('Não foi possível criar a planilha.');
        fwrite($out, "\u{FEFF}");
        fputcsv($out, self::COLUNAS, ';', escape: '');
        $total = 0;

        DocumentFilter::apply($this->export->organization, $this->export->filters)
            ->with('company:id,cnpj')
            ->lazyById(1000)
            ->each(function (DfeDocument $d) use ($out, &$total): void {
                fputcsv($out, [
                    $d->tipo->label(),
                    Fmt::cnpj($d->company->cnpj),
                    $d->emitente_cnpj ? Fmt::cnpj($d->emitente_cnpj) : '',
                    self::texto($d->emitente_nome),
                    // Número e série vêm da própria chave de acesso (posições fixas na NF-e e no CT-e).
                    ltrim(substr($d->chave, 25, 9), '0'),
                    ltrim(substr($d->chave, 22, 3), '0') ?: '0',
                    $d->data_emissao?->timezone(Fmt::TIMEZONE)->format('d/m/Y H:i') ?? '',
                    $d->valor_total === null ? '' : number_format((float) $d->valor_total, 2, ',', ''),
                    $d->situacao->label(),
                    $d->manifestacao_status?->label() ?? '',
                    $d->tem_xml_completo ? 'Sim' : 'Não',
                    // Aspas não bastam: o Excel converteria a chave de 44 dígitos em notação científica.
                    '="'.$d->chave.'"',
                ], ';', escape: '');
                $total++;
            });

        rewind($out);
        $path = "exports/{$this->export->organization_id}/{$this->export->id}.csv";
        Storage::disk(config('sefaz.xml_disk'))->writeStream($path, $out);
        fclose($out);

        $this->export->update(['status' => ExportStatus::Done, 'total' => $total, 'path' => $path]);
    }

    /** Texto vindo do XML de terceiros: sem isso, um nome começando com "=" viraria fórmula no Excel. */
    private static function texto(?string $value): string
    {
        $value ??= '';

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    public function failed(?Throwable $exception): void
    {
        $this->export->update(['status' => ExportStatus::Failed, 'error' => 'Falha ao gerar a planilha. Tente novamente.']);
    }
}
