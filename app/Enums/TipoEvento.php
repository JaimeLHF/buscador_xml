<?php

namespace App\Enums;

enum TipoEvento: string
{
    case Cancelamento = '110111';
    case CancelamentoSubstituicao = '110112';
    case CartaCorrecao = '110110';
    case ConfirmacaoOperacao = '210200';
    case CienciaOperacao = '210210';
    case DesconhecimentoOperacao = '210220';
    case OperacaoNaoRealizada = '210240';

    /** @return list<self> */
    public static function manifestacoes(): array
    {
        return [self::CienciaOperacao, self::ConfirmacaoOperacao, self::DesconhecimentoOperacao, self::OperacaoNaoRealizada];
    }

    public function isManifestacao(): bool
    {
        return in_array($this, self::manifestacoes(), true);
    }

    public function exigeJustificativa(): bool
    {
        return $this === self::OperacaoNaoRealizada;
    }

    public function label(): string
    {
        return match ($this) {
            self::Cancelamento => 'Cancelamento',
            self::CancelamentoSubstituicao => 'Cancelamento por substituição',
            self::CartaCorrecao => 'Carta de Correção',
            self::ConfirmacaoOperacao => 'Confirmação da Operação',
            self::CienciaOperacao => 'Ciência da Operação',
            self::DesconhecimentoOperacao => 'Desconhecimento da Operação',
            self::OperacaoNaoRealizada => 'Operação não Realizada',
        };
    }

    public function cancelaDocumento(): bool
    {
        return $this === self::Cancelamento || $this === self::CancelamentoSubstituicao;
    }
}
