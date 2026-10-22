<?php

namespace App\Services\Sefaz\Tools;

use NFePHP\CTe\Tools;

/** Tools do CT-e com suporte ao CNPJ alfanumérico (ver ValidatesAlphanumericCnpj). */
class CteTools extends Tools
{
    use ValidatesAlphanumericCnpj;
}
