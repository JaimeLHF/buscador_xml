<?php

namespace App\Services\MeuDanfe;

use RuntimeException;

/** HTTP 402: a conta do Meu Danfe está sem crédito. A fila para e as chaves continuam pendentes. */
class SemSaldoException extends RuntimeException {}
