<?php

// Web service da SEF-SC para contadores (modo de busca "certificado do contador").
return [

    'url' => env('SEF_SC_URL', 'https://satnfe.sef.sc.gov.br/ws/distribuicao/nfedownloadV2.asmx'),

    // BT SC-2026/003: depois de receber tudo (117, ou 118 com menos de 50 documentos), esperar no mínimo 12 horas.
    // Os 10 minutos a mais evitam o bloqueio 657 por diferença de relógio.
    'espera_sincronizado_minutos' => (int) env('SEF_SC_ESPERA_MINUTOS', 730),

    // 110 (DF-e em reprocessamento): tentar de novo em 1 hora.
    'espera_reprocessamento_minutos' => 60,

    'lote_maximo' => 50,

];
