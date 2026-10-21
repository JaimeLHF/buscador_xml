<?php

namespace App\Services\Sefaz;

/** Pedido assinado (envEvento) e resposta bruta da SEFAZ, necessários para montar o procEventoNFe. */
final readonly class EventoResponse
{
    public function __construct(
        public string $request,
        public string $response,
    ) {}
}
