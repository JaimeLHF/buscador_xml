<?php

namespace App\Enums;

/** De onde vêm as notas de uma empresa. */
enum ModoBusca: string
{
    /** NFeDistribuicaoDFe/CTeDistribuicaoDFe no Ambiente Nacional, com o certificado da própria empresa. */
    case CertificadoEmpresa = 'certificado_empresa';

    /** Web service da SEF-SC para contadores, com um único certificado do escritório para todas as empresas. */
    case ContadorSc = 'contador_sc';

    public function label(): string
    {
        return match ($this) {
            self::CertificadoEmpresa => 'Certificado da própria empresa',
            self::ContadorSc => 'Certificado do contador (só SC)',
        };
    }

    public function resumo(): string
    {
        return match ($this) {
            self::CertificadoEmpresa => 'Busca NF-e e CT-e de todo o Brasil na Receita. Precisa do certificado A1 da empresa e permite manifestar (Ciência, Confirmação, desacordo).',
            self::ContadorSc => 'Para empresas de Santa Catarina. Um único certificado do escritório contábil busca as NF-e emitidas e recebidas de todas as empresas, sem o certificado de cada uma.',
        };
    }

    /** @return list<string> */
    public function limitacoes(): array
    {
        return match ($this) {
            self::CertificadoEmpresa => [],
            self::ContadorSc => [
                'O titular do certificado precisa estar cadastrado na SEF-SC como contador da empresa.',
                'As notas chegam com cerca de 1 dia de atraso e só dos últimos 3 meses.',
                'Não busca CT-e e não permite manifestar (isso exige o certificado da empresa).',
                'Só funciona em produção: a SEF-SC não tem ambiente de testes para este serviço.',
            ],
        };
    }

    public function badgeColor(): string
    {
        return $this === self::ContadorSc ? 'violet' : 'zinc';
    }
}
