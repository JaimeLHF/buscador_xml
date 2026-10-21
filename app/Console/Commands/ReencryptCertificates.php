<?php

namespace App\Console\Commands;

use App\Models\Certificate;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('certificates:reencrypt')]
#[Description('Recriptografa certificados e senhas com a CERT_ENCRYPTION_KEY atual (após rotação de chave)')]
class ReencryptCertificates extends Command
{
    public function handle(): int
    {
        $count = 0;

        // O cast lê com a chave atual ou com uma das anteriores e grava sempre com a atual.
        Certificate::query()->lazyById(100)->each(function (Certificate $certificate) use (&$count): void {
            $certificate->forceFill([
                'pfx' => $certificate->pfx,
                'password' => $certificate->password,
            ])->save();
            $count++;
        });

        $this->components->info("{$count} certificado(s) recriptografado(s). Já é possível remover CERT_ENCRYPTION_PREVIOUS_KEYS.");

        return self::SUCCESS;
    }
}
