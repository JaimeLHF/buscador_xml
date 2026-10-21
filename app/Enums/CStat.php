<?php

namespace App\Enums;

/** Códigos de retorno relevantes do NFeDistribuicaoDFe (NT 2014.002) e de eventos. */
enum CStat: string
{
    case NenhumDocumento = '137';
    case DocumentosLocalizados = '138';
    case ConsumoIndevido = '656';
    case EventoVinculado = '135';
    case EventoVinculadoSemNota = '136';
    case DuplicidadeEvento = '573';

    public static function isDistribuicaoOk(?string $cstat): bool
    {
        return in_array($cstat, [self::NenhumDocumento->value, self::DocumentosLocalizados->value], true);
    }
}
