<?php

namespace App\Enums;

enum AuditAcao: string
{
    case XmlBaixado = 'xml_baixado';
    case DanfeAberto = 'danfe_aberto';
    case Manifestacao = 'manifestacao';
    case ConfirmacaoLote = 'confirmacao_lote';
    case ExportacaoPedida = 'exportacao_pedida';
    case ExportacaoBaixada = 'exportacao_baixada';
    case CertificadoEnviado = 'certificado_enviado';
    case TokenCriado = 'token_criado';
    case TokenRevogado = 'token_revogado';
    case WebhookAlterado = 'webhook_alterado';
    case ConsultaNfeCompleta = 'consulta_nfe_completa';

    public function label(): string
    {
        return match ($this) {
            self::XmlBaixado => 'Baixou o XML',
            self::DanfeAberto => 'Abriu o DANFE/DACTE',
            self::Manifestacao => 'Manifestação registrada',
            self::ConfirmacaoLote => 'Pediu Confirmação em lote',
            self::ExportacaoPedida => 'Pediu um download',
            self::ExportacaoBaixada => 'Baixou um download',
            self::CertificadoEnviado => 'Enviou certificado',
            self::TokenCriado => 'Criou chave de API',
            self::TokenRevogado => 'Revogou chave de API',
            self::WebhookAlterado => 'Alterou o webhook',
            self::ConsultaNfeCompleta => 'Pediu consulta completa de NF-e',
        };
    }
}
