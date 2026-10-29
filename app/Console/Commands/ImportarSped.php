<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\MeuDanfe\SpedChaves;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('historico:importar-sped {company : ID da empresa} {arquivos* : Arquivos SPED EFD ICMS/IPI}')]
#[Description('Põe na fila do Meu Danfe as NF-e de entrada dos arquivos SPED')]
class ImportarSped extends Command
{
    public function handle(): int
    {
        $company = Company::findOrFail($this->argument('company'));

        foreach ($this->argument('arquivos') as $arquivo) {
            $r = SpedChaves::importar($company, (string) file_get_contents($arquivo));
            $this->line(basename($arquivo).": {$r['lidas']} chaves, {$r['novas']} novas na fila");
        }

        return self::SUCCESS;
    }
}
