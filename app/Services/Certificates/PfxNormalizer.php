<?php

namespace App\Services\Certificates;

use App\Services\Certificates\Exceptions\InvalidCertificateException;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Garante um PKCS#12 legível pelo OpenSSL 3 sem o legacy provider.
 *
 * .pfx com RC2/3DES não abrem via openssl_pkcs12_read() no OpenSSL 3, e a extensão do PHP não
 * carrega o legacy provider mesmo com OPENSSL_CONF. Nesses casos o binário openssl (que aceita -legacy)
 * extrai as chaves e o arquivo é regravado com os algoritmos padrão (AES-256/PBKDF2).
 */
class PfxNormalizer
{
    public function normalize(string $pfx, string $password): string
    {
        $parts = $this->readNative($pfx, $password) ?? $this->readLegacy($pfx, $password);

        $exported = '';
        $ok = openssl_pkcs12_export(
            $parts['cert'],
            $exported,
            $parts['pkey'],
            $password,
            ['extracerts' => $parts['extracerts']],
        );

        if (! $ok) {
            throw InvalidCertificateException::unreadable();
        }

        return $exported;
    }

    /**
     * @return array{cert: string, pkey: string, extracerts: list<string>}|null
     */
    private function readNative(string $pfx, string $password): ?array
    {
        $certs = [];

        if (openssl_pkcs12_read($pfx, $certs, $password)) {
            $this->clearErrors();

            return [
                'cert' => $certs['cert'],
                'pkey' => $certs['pkey'],
                'extracerts' => array_values($certs['extracerts'] ?? []),
            ];
        }

        $errors = $this->clearErrors();

        if (Str::contains($errors, 'mac verify failure', ignoreCase: true)) {
            throw InvalidCertificateException::wrongPassword();
        }

        return null;
    }

    /**
     * @return array{cert: string, pkey: string, extracerts: list<string>}
     */
    private function readLegacy(string $pfx, string $password): array
    {
        $dir = sys_get_temp_dir().'/pfx-'.Str::random(32);
        mkdir($dir, 0700);
        $input = $dir.'/in.pfx';

        try {
            file_put_contents($input, $pfx);
            chmod($input, 0600);

            // Senha via variável de ambiente para não aparecer na lista de processos.
            $process = new Process(
                [config('certificates.openssl_binary'), 'pkcs12', '-legacy', '-nodes', '-in', $input, '-passin', 'env:PFX_PASS'],
                env: ['PFX_PASS' => $password],
                timeout: 15,
            );
            $process->run();

            if (! $process->isSuccessful()) {
                if (Str::contains($process->getErrorOutput(), ['Mac verify error', 'invalid password'], ignoreCase: true)) {
                    throw InvalidCertificateException::wrongPassword();
                }

                throw InvalidCertificateException::unreadable();
            }

            return $this->splitPem($process->getOutput());
        } finally {
            @unlink($input);
            @rmdir($dir);
        }
    }

    /**
     * @return array{cert: string, pkey: string, extracerts: list<string>}
     */
    private function splitPem(string $pem): array
    {
        preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $certs);
        preg_match('/-----BEGIN (?:RSA |ENCRYPTED )?PRIVATE KEY-----.+?-----END (?:RSA |ENCRYPTED )?PRIVATE KEY-----/s', $pem, $key);

        if ($certs[0] === [] || $key === []) {
            throw InvalidCertificateException::unreadable();
        }

        $privateKey = openssl_pkey_get_private($key[0]);

        // O certificado do titular é o que corresponde à chave privada; os demais são a cadeia.
        foreach ($certs[0] as $i => $cert) {
            if ($privateKey !== false && openssl_x509_check_private_key($cert, $privateKey)) {
                $chain = $certs[0];
                unset($chain[$i]);

                return ['cert' => $cert, 'pkey' => $key[0], 'extracerts' => array_values($chain)];
            }
        }

        throw InvalidCertificateException::unreadable();
    }

    private function clearErrors(): string
    {
        $errors = '';

        while (($error = openssl_error_string()) !== false) {
            $errors .= $error."\n";
        }

        return $errors;
    }
}
