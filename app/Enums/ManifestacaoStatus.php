<?php

namespace App\Enums;

enum ManifestacaoStatus: string
{
    case CienciaPendente = 'ciencia_pendente';
    case Ciencia = 'ciencia';
    case Confirmada = 'confirmada';
    case Desconhecida = 'desconhecida';
    case NaoRealizada = 'nao_realizada';
    case Erro = 'erro';

    public static function fromEvento(TipoEvento $tipo): ?self
    {
        return match ($tipo) {
            TipoEvento::CienciaOperacao => self::Ciencia,
            TipoEvento::ConfirmacaoOperacao => self::Confirmada,
            TipoEvento::DesconhecimentoOperacao => self::Desconhecida,
            TipoEvento::OperacaoNaoRealizada => self::NaoRealizada,
            default => null,
        };
    }

    /** Confirmação, Desconhecimento e Operação não Realizada encerram a manifestação. */
    public function isConclusive(): bool
    {
        return in_array($this, [self::Confirmada, self::Desconhecida, self::NaoRealizada], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::CienciaPendente => 'Ciência pendente',
            self::Ciencia => 'Ciência da Operação',
            self::Confirmada => 'Operação confirmada',
            self::Desconhecida => 'Operação desconhecida',
            self::NaoRealizada => 'Operação não realizada',
            self::Erro => 'Erro na manifestação',
        };
    }
}
