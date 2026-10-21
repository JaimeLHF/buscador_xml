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

    public function cancelaDocumento(): bool
    {
        return $this === self::Cancelamento || $this === self::CancelamentoSubstituicao;
    }
}
