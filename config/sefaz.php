<?php

return [

    // 1 = produção, 2 = homologação. Padrão de desenvolvimento é sempre homologação.
    'tp_amb' => (int) env('SEFAZ_TP_AMB', 2),

    // NT 2014.002: após cStat 137 ou 656 só se pode consultar o mesmo CNPJ depois de 1 hora.
    'wait_after_no_docs_minutes' => (int) env('SEFAZ_WAIT_AFTER_137', 65),
    'wait_after_656_minutes' => (int) env('SEFAZ_WAIT_AFTER_656', 65),

    // Limite da NT para consChNFe/consNSU: 20 consultas por hora por CNPJ.
    'cons_chave_per_hour' => 20,

    'timeout_seconds' => (int) env('SEFAZ_TIMEOUT', 30),

];
