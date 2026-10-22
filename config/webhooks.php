<?php

return [

    // Fila dos avisos ao ERP; nunca a da SEFAZ, para uma carga inicial grande não atrasar as consultas.
    'queue' => env('WEBHOOK_QUEUE', 'default'),

];
