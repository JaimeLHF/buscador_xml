<?php

namespace App\Enums;

enum ChaveHistoricaStatus: string
{
    case Pendente = 'pendente';
    case Encontrada = 'encontrada';
    case NaoEncontrada = 'nao_encontrada';
    case Erro = 'erro';

    public function label(): string
    {
        return match ($this) {
            self::Pendente => __('Na fila'),
            self::Encontrada => __('XML recebido'),
            self::NaoEncontrada => __('Não encontrada'),
            self::Erro => __('Erro'),
        };
    }
}
