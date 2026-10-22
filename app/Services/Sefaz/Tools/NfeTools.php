<?php

namespace App\Services\Sefaz\Tools;

use NFePHP\NFe\Tools;

/** Tools da NF-e com suporte ao CNPJ alfanumérico (ver ValidatesAlphanumericCnpj). */
class NfeTools extends Tools
{
    use ValidatesAlphanumericCnpj;
}
