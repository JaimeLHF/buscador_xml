<?php

namespace App\Http\Controllers;

use App\Enums\AuditAcao;
use App\Enums\DocumentoTipo;
use App\Models\DfeDocument;
use App\Services\Documents\DanfeRenderer;
use App\Services\Sefaz\XmlStorage;
use App\Support\Audit;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class DocumentFileController extends Controller
{
    public function xml(DfeDocument $document, XmlStorage $storage): Response
    {
        Gate::authorize('view', $document);

        $path = $document->xml_path ?? $document->resumo_xml_path;
        $content = $path ? $storage->get($path) : null;
        abort_if($content === null, 404);

        $suffix = $document->tem_xml_completo ? $document->schema->value : 'resNFe';
        Audit::document(AuditAcao::XmlBaixado, $document);

        return response($content, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => "attachment; filename=\"{$document->chave}-{$suffix}.xml\"",
        ]);
    }

    public function danfe(DfeDocument $document, DanfeRenderer $renderer): Response
    {
        Gate::authorize('view', $document);

        try {
            $pdf = $renderer->render($document);
        } catch (RuntimeException $e) {
            return redirect()->route('documents.show', $document)->with('error', $e->getMessage());
        }

        Audit::document(AuditAcao::DanfeAberto, $document);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$document->chave}-".($document->tipo === DocumentoTipo::Cte ? 'dacte' : 'danfe').'.pdf"',
        ]);
    }
}
