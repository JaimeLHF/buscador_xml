<?php

return [

    // Chave dedicada para criptografar .pfx e senha em repouso, separada da APP_KEY.
    'key' => env('CERT_ENCRYPTION_KEY'),

    // Rotação: mova a chave antiga para cá (separadas por vírgula), troque CERT_ENCRYPTION_KEY e rode certificates:reencrypt.
    'previous_keys' => array_filter(explode(',', (string) env('CERT_ENCRYPTION_PREVIOUS_KEYS', ''))),

    'cipher' => 'AES-256-GCM',

    'openssl_binary' => env('OPENSSL_BINARY', 'openssl'),

];
