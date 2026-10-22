<?php

namespace App\Http\Controllers;

use App\Enums\AuditAcao;
use App\Enums\ExportFormato;
use App\Models\Export;
use App\Support\Audit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportDownloadController extends Controller
{
    public function __invoke(Export $export): StreamedResponse
    {
        Gate::authorize('download', $export);
        abort_unless($export->path && Storage::disk(config('sefaz.xml_disk'))->exists($export->path), 404);
        Audit::record(AuditAcao::ExportacaoBaixada, $export->organization_id, descricao: $export->formato->label(), dados: ['export_id' => $export->id, 'total' => $export->total]);

        return Storage::disk(config('sefaz.xml_disk'))->download($export->path, ($export->formato === ExportFormato::Csv ? 'notas' : 'xmls')."-{$export->created_at->format('Ymd-His')}.{$export->formato->value}");
    }
}
