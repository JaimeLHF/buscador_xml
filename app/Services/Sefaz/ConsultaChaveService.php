<?php

namespace App\Services\Sefaz;

use App\Enums\SefazServico;
use App\Models\Company;
use App\Services\Sefaz\Exceptions\SefazException;
use Throwable;

/** consChNFe: busca um documento específico. Respeita o limite de 20/hora por CNPJ antes de cada chamada. */
class ConsultaChaveService
{
    public function __construct(
        private SefazGateway $gateway,
        private RetDistDFeParser $parser,
        private DocumentProcessor $processor,
        private SefazRequestLogger $logger,
        private ConsultaLimiter $limiter,
    ) {}

    /**
     * @throws SefazException quando o limite horário foi atingido
     */
    public function consultar(Company $company, string $chave): RetDistDFe
    {
        if (! $this->limiter->attempt($company)) {
            throw SefazException::rateLimited();
        }

        $started = microtime(true);

        try {
            $ret = $this->parser->parse($this->gateway->consChave($company, $chave));
        } catch (Throwable $e) {
            $this->logger->logError($company, SefazServico::ConsChave, $started, $e->getMessage(), chave: $chave);

            throw $e;
        }

        $this->logger->log($company, SefazServico::ConsChave, $started, $ret, chave: $chave);

        foreach ($ret->documents as $doc) {
            $this->processor->process($company, $doc);
        }

        return $ret;
    }
}
