<?php

return [

    // A listagem só entrega notas alteradas há mais que isto (ver DocumentController::index): o cursor não pula
    // linha gravada por uma transação que ainda não tinha feito commit na hora da leitura.
    'assentamento_segundos' => (int) env('API_ASSENTAMENTO_SEGUNDOS', 120),

];
