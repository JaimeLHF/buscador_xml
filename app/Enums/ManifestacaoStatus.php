<?php

namespace App\Enums;

enum ManifestacaoStatus: string
{
    case CienciaPendente = 'ciencia_pendente';
    case Ciencia = 'ciencia';
    case ConfirmacaoPendente = 'confirmacao_pendente';
    case Confirmada = 'confirmada';
    case Desconhecida = 'desconhecida';
    case NaoRealizada = 'nao_realizada';
    case Desacordo = 'desacordo';
    case Erro = 'erro';

    public static function fromEvento(TipoEvento $tipo): ?self
    {
        return match ($tipo) {
            TipoEvento::CienciaOperacao => self::Ciencia,
            TipoEvento::ConfirmacaoOperacao => self::Confirmada,
            TipoEvento::DesconhecimentoOperacao => self::Desconhecida,
            TipoEvento::OperacaoNaoRealizada => self::NaoRealizada,
            TipoEvento::PrestacaoDesacordo => self::Desacordo,
            default => null,
        };
    }

    /** Confirmação, Desconhecimento, Operação não Realizada e o desacordo do CT-e encerram a manifestação. */
    public function isConclusive(): bool
    {
        return in_array($this, [self::Confirmada, self::Desconhecida, self::NaoRealizada, self::Desacordo], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::CienciaPendente => 'Ciência pendente',
            self::Ciencia => 'Ciência da Operação',
            self::ConfirmacaoPendente => 'Confirmação pendente',
            self::Confirmada => 'Operação confirmada',
            self::Desconhecida => 'Operação desconhecida',
            self::NaoRealizada => 'Operação não realizada',
            self::Desacordo => 'Serviço em desacordo',
            self::Erro => 'Erro na manifestação',
        };
    }
}
