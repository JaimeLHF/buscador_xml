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
    case PrestacaoDesacordo = '610110';

    /** @return list<self> */
    public static function manifestacoes(): array
    {
        return [self::CienciaOperacao, self::ConfirmacaoOperacao, self::DesconhecimentoOperacao, self::OperacaoNaoRealizada];
    }

    /**
     * Eventos que o próprio destinatário (NF-e) ou tomador (CT-e) pode enviar para o documento.
     *
     * @return list<self>
     */
    public static function manifestacoesDe(DocumentoTipo $tipo): array
    {
        return match ($tipo) {
            DocumentoTipo::Nfe => self::manifestacoes(),
            DocumentoTipo::Cte => [self::PrestacaoDesacordo],
        };
    }

    public function isManifestacao(): bool
    {
        return in_array($this, [...self::manifestacoes(), self::PrestacaoDesacordo], true);
    }

    /** O desacordo do CT-e vai para a SEFAZ autorizadora do conhecimento; os demais, para o Ambiente Nacional da NF-e. */
    public function documentoTipo(): DocumentoTipo
    {
        return $this === self::PrestacaoDesacordo ? DocumentoTipo::Cte : DocumentoTipo::Nfe;
    }

    public function exigeJustificativa(): bool
    {
        return $this === self::OperacaoNaoRealizada || $this === self::PrestacaoDesacordo;
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
            self::PrestacaoDesacordo => 'Prestação do serviço em desacordo',
        };
    }

    public function cancelaDocumento(): bool
    {
        return $this === self::Cancelamento || $this === self::CancelamentoSubstituicao;
    }
}
