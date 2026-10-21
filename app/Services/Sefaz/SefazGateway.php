<?php

namespace App\Services\Sefaz;

use App\Enums\TipoEvento;
use App\Models\Company;

/** Fronteira com a SEFAZ. Retorna o XML bruto da resposta; os testes substituem esta interface. */
interface SefazGateway
{
    public function distNsu(Company $company, string $ultNsu): string;

    public function consNsu(Company $company, string $nsu): string;

    public function consChave(Company $company, string $chave): string;

    /** Envia o evento de manifestação (assinado) ao Ambiente Nacional. */
    public function manifestar(Company $company, string $chave, TipoEvento $tipo, int $nSeqEvento, string $xJust = ''): EventoResponse;
}
