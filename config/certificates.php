<?php

return [

    // Chave dedicada para criptografar .pfx e senha em repouso, separada da APP_KEY.
    'key' => env('CERT_ENCRYPTION_KEY'),

    'cipher' => 'AES-256-GCM',

    'openssl_binary' => env('OPENSSL_BINARY', 'openssl'),

];
