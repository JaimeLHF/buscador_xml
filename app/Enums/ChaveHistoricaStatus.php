<?php

namespace App\Enums;

enum ChaveHistoricaStatus: string
{
    /** Veio do SPED e espera alguém marcá-la para a consulta (paga) no Meu Danfe. */
    case Importada = 'importada';
    case Pendente = 'pendente';
    case Encontrada = 'encontrada';
    case NaoEncontrada = 'nao_encontrada';
    case Erro = 'erro';

    public function label(): string
    {
        return match ($this) {
            self::Importada => __('Aguardando seleção'),
            self::Pendente => __('Na fila'),
            self::Encontrada => __('XML recebido'),
            self::NaoEncontrada => __('Não encontrada'),
            self::Erro => __('Erro'),
        };
    }

    /** Pode ser marcada para consultar (de novo) no Meu Danfe. */
    public function selecionavel(): bool
    {
        return in_array($this, [self::Importada, self::NaoEncontrada, self::Erro], true);
    }

    public function cor(): string
    {
        return match ($this) {
            self::Importada => 'amber',
            self::Pendente => 'blue',
            self::Encontrada => 'green',
            self::Erro => 'red',
            self::NaoEncontrada => 'zinc',
        };
    }
}
