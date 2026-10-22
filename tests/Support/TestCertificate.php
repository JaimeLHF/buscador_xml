<?php

namespace Tests\Support;

use Symfony\Component\Process\Process;

/**
 * Gera e-CNPJ autoassinados com o OID ICP-Brasil 2.16.76.1.3.3 (CNPJ do titular) no subjectAltName,
 * igual ao que o sped-common extrai de certificados reais. Para vencido/futuro, use $this->travel() nos testes.
 */
final class TestCertificate
{
    public static function pfx(
        string $cnpj = '12345678000195',
        string $password = '1234',
        int $validDays = 365,
        bool $legacy = false,
        bool $withCnpj = true,
        ?string $cpf = null,
    ): string {
        $dir = sys_get_temp_dir().'/testcert-'.bin2hex(random_bytes(6));
        mkdir($dir);

        try {
            // e-CPF: o OID 2.16.76.1.3.1 traz data de nascimento (8) + CPF (11) + outros dados.
            $san = match (true) {
                $cpf !== null => "subjectAltName=otherName:2.16.76.1.3.1;UTF8:01011980{$cpf}00000000000000000000000000000\n",
                $withCnpj => "subjectAltName=otherName:2.16.76.1.3.2;UTF8:RESPONSAVEL,otherName:2.16.76.1.3.3;UTF8:{$cnpj}\n",
                default => "subjectAltName=email:teste@example.com\n",
            };

            file_put_contents("{$dir}/openssl.cnf", "[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN=EMPRESA TESTE LTDA:{$cnpj}\nO=ICP-Brasil\n[ext]\n{$san}");

            self::run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', "{$dir}/key.pem", '-out', "{$dir}/cert.pem",
                '-config', "{$dir}/openssl.cnf", '-extensions', 'ext', '-days', (string) $validDays]);

            self::run(array_merge(
                ['openssl', 'pkcs12', '-export', '-inkey', "{$dir}/key.pem", '-in', "{$dir}/cert.pem", '-out', "{$dir}/out.pfx", '-passout', "pass:{$password}"],
                $legacy ? ['-legacy'] : [],
            ));

            return (string) file_get_contents("{$dir}/out.pfx");
        } finally {
            array_map('unlink', glob("{$dir}/*") ?: []);
            rmdir($dir);
        }
    }

    /**
     * @param  list<string>  $command
     */
    private static function run(array $command): void
    {
        $process = new Process($command);
        $process->mustRun();
    }
}
