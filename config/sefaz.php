<?php

return [

    // 1 = produção, 2 = homologação. Padrão de desenvolvimento é sempre homologação.
    'tp_amb' => (int) env('SEFAZ_TP_AMB', 2),

    // Trava de segurança: empresas em produção só consultam a SEFAZ com isto explicitamente ligado.
    'allow_production' => (bool) env('SEFAZ_ALLOW_PRODUCTION', false),

    // NT 2014.002: após cStat 137 ou 656 só se pode consultar o mesmo CNPJ depois de 1 hora.
    'wait_after_no_docs_minutes' => (int) env('SEFAZ_WAIT_AFTER_137', 65),
    'wait_after_656_minutes' => (int) env('SEFAZ_WAIT_AFTER_656', 65),

    // Falha em que a requisição certamente não chegou à SEFAZ (DNS, conexão, TLS): backoff exponencial 2, 4, 8… até 60 min.
    // Falhas ambíguas (timeout, 5xx, resposta vazia) esperam a hora inteira da NT. Ver FailureClassifier.
    'retry_base_minutes' => (int) env('SEFAZ_RETRY_BASE_MINUTES', 2),
    'retry_max_minutes' => (int) env('SEFAZ_RETRY_MAX_MINUTES', 60),

    // Páginas de distNSU por execução do job; se ainda houver documentos, continua no minuto seguinte.
    'max_pages_per_run' => (int) env('SEFAZ_MAX_PAGES', 20),

    // Limite da NT para consChNFe/consNSU: 20 consultas por hora por CNPJ.
    'cons_chave_per_hour' => 20,

    // A SEFAZ só aceita a Ciência da Operação até 10 dias após a autorização da NF-e (rejeição 596).
    'ciencia_prazo_dias' => (int) env('SEFAZ_CIENCIA_PRAZO_DIAS', 10),

    // Confirmação, Desconhecimento e Operação não Realizada: até 180 dias da autorização.
    'confirmacao_prazo_dias' => (int) env('SEFAZ_CONFIRMACAO_PRAZO_DIAS', 180),

    // Prestação do serviço em desacordo (CT-e, 610110): só o tomador, até 45 dias da autorização.
    'desacordo_prazo_dias' => (int) env('SEFAZ_DESACORDO_PRAZO_DIAS', 45),

    // Alerta por e-mail/painel quando a empresa acumula esta quantidade de 656 seguidos.
    'blocked_alert_after' => (int) env('SEFAZ_BLOCKED_ALERT_AFTER', 3),

    'timeout_seconds' => (int) env('SEFAZ_TIMEOUT', 30),

    'xml_disk' => env('SEFAZ_XML_DISK', 'local'),

    'queue' => env('SEFAZ_QUEUE', 'sefaz'),

];
