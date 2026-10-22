<?php

namespace App\Services\Documents;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * Lê um procNFe/NFe (ou resNFe) ou um procCTe/CT-e OS e devolve os dados organizados para a tela do documento,
 * com códigos traduzidos. Só leitura: o XML guardado nunca é alterado.
 */
final class NfeReader
{
    private const NS_NFE = 'http://www.portalfiscal.inf.br/nfe';

    private const NS_CTE = 'http://www.portalfiscal.inf.br/cte';

    private DOMXPath $xp;

    public function __construct(private string $xml) {}

    /** @return array<string, mixed> */
    public function read(): array
    {
        $dom = new DOMDocument;

        // LIBXML_NONET: nunca busca nada na rede; entidades externas não são carregadas no PHP 8.
        if (! @$dom->loadXML($this->xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
            throw new RuntimeException('XML inválido.');
        }

        $this->xp = new DOMXPath($dom);
        // O prefixo "n" aponta para o namespace do documento: NF-e e CT-e usam os mesmos caminhos relativos.
        $cte = $dom->documentElement?->namespaceURI === self::NS_CTE;
        $this->xp->registerNamespace('n', $cte ? self::NS_CTE : self::NS_NFE);

        if ($cte) {
            return $this->cte($dom);
        }

        if ($res = $this->node('//n:resNFe')) {
            return ['tipo' => 'resumo', 'resumo' => $this->resumo($res), 'xml' => $this->pretty($dom)];
        }

        $inf = $this->node('//n:infNFe') ?? throw new RuntimeException('XML não é uma NF-e.');

        return [
            'tipo' => 'completo',
            'ide' => $this->ide($inf),
            'emitente' => $this->parte($this->node('n:emit', $inf), 'enderEmit'),
            'destinatario' => $this->parte($this->node('n:dest', $inf), 'enderDest'),
            'itens' => array_map(fn (DOMElement $det) => $this->item($det), $this->nodes('n:det', $inf)),
            'totais' => $this->totais($inf),
            'transporte' => $this->transporte($inf),
            'cobranca' => $this->cobranca($inf),
            'pagamentos' => $this->pagamentos($inf),
            'adicionais' => array_filter([
                'Informações complementares' => $this->text('n:infAdic/n:infCpl', $inf),
                'Informações ao Fisco' => $this->text('n:infAdic/n:infAdFisco', $inf),
            ]),
            'observacoes' => array_map(fn (DOMElement $o) => ['campo' => $o->getAttribute('xCampo'), 'texto' => $this->text('n:xTexto', $o)], $this->nodes('n:infAdic/n:obsCont', $inf)),
            'autorizados' => array_values(array_filter(array_map(fn (DOMElement $a) => $this->text('n:CNPJ', $a) ?? $this->text('n:CPF', $a), $this->nodes('n:autXML', $inf)))),
            'responsavel' => $this->campos($this->node('n:infRespTec', $inf), ['CNPJ' => 'CNPJ', 'xContato' => 'Contato', 'email' => 'E-mail', 'fone' => 'Telefone']),
            'protocolo' => $this->campos($this->node('//n:protNFe/n:infProt'), ['nProt' => 'Protocolo', 'dhRecbto' => 'Autorizada em', 'cStat' => 'Código', 'xMotivo' => 'Situação', 'digVal' => 'Digest']),
            'xml' => $this->pretty($dom),
        ];
    }

    /** @return array<string, string> */
    private function ide(DOMElement $inf): array
    {
        $ide = $this->node('n:ide', $inf);

        return array_filter([
            'Número' => $this->text('n:nNF', $ide),
            'Série' => $this->text('n:serie', $ide),
            'Modelo' => self::code(['55' => '55 – NF-e', '65' => '65 – NFC-e'], $this->text('n:mod', $ide)),
            'Natureza da operação' => $this->text('n:natOp', $ide),
            'Tipo' => self::code(['0' => 'Entrada', '1' => 'Saída'], $this->text('n:tpNF', $ide)),
            'Finalidade' => self::code(['1' => 'Normal', '2' => 'Complementar', '3' => 'Ajuste', '4' => 'Devolução'], $this->text('n:finNFe', $ide)),
            'Destino' => self::code(['1' => 'Operação interna', '2' => 'Interestadual', '3' => 'Exterior'], $this->text('n:idDest', $ide)),
            'Emissão' => $this->text('n:dhEmi', $ide),
            'Saída/entrada' => $this->text('n:dhSaiEnt', $ide),
            'Consumidor final' => self::code(['0' => 'Não', '1' => 'Sim'], $this->text('n:indFinal', $ide)),
            'Presença do comprador' => self::code(['0' => 'Não se aplica', '1' => 'Presencial', '2' => 'Internet', '3' => 'Teleatendimento', '4' => 'Entrega em domicílio', '5' => 'Presencial fora do estabelecimento', '9' => 'Outros'], $this->text('n:indPres', $ide)),
            'Forma de emissão' => self::code(['1' => 'Normal', '2' => 'Contingência FS-IA', '4' => 'Contingência EPEC', '5' => 'Contingência FS-DA', '6' => 'Contingência SVC-AN', '7' => 'Contingência SVC-RS', '9' => 'Contingência off-line NFC-e'], $this->text('n:tpEmis', $ide)),
            'Ambiente' => self::code(['1' => 'Produção', '2' => 'Homologação'], $this->text('n:tpAmb', $ide)),
            'Notas referenciadas' => implode(', ', array_map(fn ($n) => $n->nodeValue, $this->nodes('n:NFref/n:refNFe', $ide))) ?: null,
        ]);
    }

    /** @return array<string, string>|null */
    private function parte(?DOMElement $p, string $enderTag): ?array
    {
        if (! $p) {
            return null;
        }

        $end = $this->node('n:'.$enderTag, $p);
        $endereco = $end ? trim(implode(', ', array_filter([
            trim(($this->text('n:xLgr', $end) ?? '').' '.($this->text('n:nro', $end) ?? '')),
            $this->text('n:xCpl', $end),
            $this->text('n:xBairro', $end),
            trim(($this->text('n:xMun', $end) ?? '').'/'.($this->text('n:UF', $end) ?? ''), '/'),
            ($cep = $this->text('n:CEP', $end)) ? 'CEP '.substr($cep, 0, 5).'-'.substr($cep, 5) : null,
        ]))) : null;

        return array_filter([
            'Nome' => $this->text('n:xNome', $p),
            'Nome fantasia' => $this->text('n:xFant', $p),
            'CNPJ' => $this->text('n:CNPJ', $p),
            'CPF' => $this->text('n:CPF', $p),
            'Inscrição estadual' => $this->text('n:IE', $p),
            'Inscrição municipal' => $this->text('n:IM', $p),
            'Regime tributário' => self::code(['1' => 'Simples Nacional', '2' => 'Simples Nacional – excesso de sublimite', '3' => 'Regime normal', '4' => 'MEI'], $this->text('n:CRT', $p)),
            'Endereço' => $endereco ?: null,
            'Telefone' => $end ? $this->text('n:fone', $end) : null,
            'E-mail' => $this->text('n:email', $p),
        ]);
    }

    /** @return array<string, mixed> */
    private function item(DOMElement $det): array
    {
        $prod = $this->node('n:prod', $det);
        $imp = $this->node('n:imposto', $det);
        $icms = $imp ? $this->node('n:ICMS/*', $imp) : null;

        return [
            'n' => $det->getAttribute('nItem'),
            'codigo' => $this->text('n:cProd', $prod),
            'descricao' => $this->text('n:xProd', $prod),
            'ean' => ($ean = $this->text('n:cEAN', $prod)) === 'SEM GTIN' ? null : $ean,
            'ncm' => $this->text('n:NCM', $prod),
            'cest' => $this->text('n:CEST', $prod),
            'cfop' => $this->text('n:CFOP', $prod),
            'unidade' => $this->text('n:uCom', $prod),
            'quantidade' => $this->text('n:qCom', $prod),
            'unitario' => $this->text('n:vUnCom', $prod),
            'total' => $this->text('n:vProd', $prod),
            'desconto' => $this->text('n:vDesc', $prod),
            'frete' => $this->text('n:vFrete', $prod),
            'pedido' => $this->text('n:xPed', $prod),
            'info' => $this->text('n:infAdProd', $det),
            'impostos' => array_filter([
                'ICMS' => $icms ? array_filter([
                    'CST' => ($this->text('n:orig', $icms) ?? '').($this->text('n:CST', $icms) ?? $this->text('n:CSOSN', $icms) ?? ''),
                    'Base' => $this->text('n:vBC', $icms),
                    'Alíquota' => $this->text('n:pICMS', $icms),
                    'Valor' => $this->text('n:vICMS', $icms),
                    'Base ST' => $this->text('n:vBCST', $icms),
                    'Valor ST' => $this->text('n:vICMSST', $icms),
                ]) : null,
                'IPI' => $imp ? $this->campos($this->node('n:IPI/n:IPITrib', $imp), ['CST' => 'CST', 'vBC' => 'Base', 'pIPI' => 'Alíquota', 'vIPI' => 'Valor']) : null,
                'PIS' => $imp ? $this->campos($this->node('n:PIS/*', $imp), ['CST' => 'CST', 'vBC' => 'Base', 'pPIS' => 'Alíquota', 'vPIS' => 'Valor']) : null,
                'COFINS' => $imp ? $this->campos($this->node('n:COFINS/*', $imp), ['CST' => 'CST', 'vBC' => 'Base', 'pCOFINS' => 'Alíquota', 'vCOFINS' => 'Valor']) : null,
            ]),
        ];
    }

    /** @return array<string, string> */
    private function totais(DOMElement $inf): array
    {
        return $this->campos($this->node('n:total/n:ICMSTot', $inf), [
            'vProd' => 'Produtos', 'vFrete' => 'Frete', 'vSeg' => 'Seguro', 'vDesc' => 'Desconto', 'vOutro' => 'Outras despesas',
            'vBC' => 'Base do ICMS', 'vICMS' => 'ICMS', 'vICMSDeson' => 'ICMS desonerado', 'vFCP' => 'FCP',
            'vBCST' => 'Base do ICMS ST', 'vST' => 'ICMS ST', 'vFCPST' => 'FCP ST', 'vFCPSTRet' => 'FCP ST retido',
            'vII' => 'Imposto de importação', 'vIPI' => 'IPI', 'vIPIDevol' => 'IPI devolvido', 'vPIS' => 'PIS', 'vCOFINS' => 'COFINS',
            'vTotTrib' => 'Tributos aproximados', 'vNF' => 'Total da nota',
        ]) ?? [];
    }

    /** @return array<string, mixed> */
    private function transporte(DOMElement $inf): array
    {
        $t = $this->node('n:transp', $inf);

        if (! $t) {
            return [];
        }

        $tr = $this->node('n:transporta', $t);
        $veic = $this->node('n:veicTransp', $t);

        return [
            'dados' => array_filter([
                'Frete por conta' => self::code(['0' => 'Emitente (CIF)', '1' => 'Destinatário (FOB)', '2' => 'Terceiros', '3' => 'Próprio remetente', '4' => 'Próprio destinatário', '9' => 'Sem frete'], $this->text('n:modFrete', $t)),
                'Transportadora' => $tr ? $this->text('n:xNome', $tr) : null,
                'CNPJ/CPF da transportadora' => $tr ? ($this->text('n:CNPJ', $tr) ?? $this->text('n:CPF', $tr)) : null,
                'IE da transportadora' => $tr ? $this->text('n:IE', $tr) : null,
                'Endereço da transportadora' => $tr ? trim(implode(' – ', array_filter([$this->text('n:xEnder', $tr), trim(($this->text('n:xMun', $tr) ?? '').'/'.($this->text('n:UF', $tr) ?? ''), '/')]))) ?: null : null,
                'Placa' => $veic ? trim(($this->text('n:placa', $veic) ?? '').' '.($this->text('n:UF', $veic) ?? '')) : null,
            ]),
            'volumes' => array_map(fn (DOMElement $v) => array_filter([
                'Quantidade' => $this->text('n:qVol', $v),
                'Espécie' => $this->text('n:esp', $v),
                'Marca' => $this->text('n:marca', $v),
                'Numeração' => $this->text('n:nVol', $v),
                'Peso líquido (kg)' => $this->text('n:pesoL', $v),
                'Peso bruto (kg)' => $this->text('n:pesoB', $v),
            ]), $this->nodes('n:vol', $t)),
        ];
    }

    /** @return array<string, mixed> */
    private function cobranca(DOMElement $inf): array
    {
        $c = $this->node('n:cobr', $inf);

        return $c ? [
            'fatura' => $this->campos($this->node('n:fat', $c), ['nFat' => 'Fatura', 'vOrig' => 'Valor original', 'vDesc' => 'Desconto', 'vLiq' => 'Valor líquido']),
            'duplicatas' => array_map(fn (DOMElement $d) => ['numero' => $this->text('n:nDup', $d), 'vencimento' => $this->text('n:dVenc', $d), 'valor' => $this->text('n:vDup', $d)], $this->nodes('n:dup', $c)),
        ] : [];
    }

    /** @return list<array<string, string|null>> */
    private function pagamentos(DOMElement $inf): array
    {
        return array_map(fn (DOMElement $p) => [
            'forma' => self::code([
                '01' => 'Dinheiro', '02' => 'Cheque', '03' => 'Cartão de crédito', '04' => 'Cartão de débito', '05' => 'Crédito loja',
                '10' => 'Vale alimentação', '11' => 'Vale refeição', '12' => 'Vale presente', '13' => 'Vale combustível',
                '15' => 'Boleto bancário', '16' => 'Depósito bancário', '17' => 'PIX', '18' => 'Transferência / carteira digital',
                '19' => 'Fidelidade / cashback', '90' => 'Sem pagamento', '99' => 'Outros',
            ], $this->text('n:tPag', $p)),
            'descricao' => $this->text('n:xPag', $p),
            'prazo' => self::code(['0' => 'À vista', '1' => 'A prazo'], $this->text('n:indPag', $p)),
            'valor' => $this->text('n:vPag', $p),
        ], $this->nodes('n:pag/n:detPag', $inf));
    }

    /** @return array<string, mixed> */
    private function cte(DOMDocument $dom): array
    {
        $inf = $this->node('//n:infCte') ?? throw new RuntimeException('XML não é um CT-e.');
        $ide = $this->node('n:ide', $inf);
        $norm = $this->node('n:infCTeNorm', $inf);
        $carga = $norm ? $this->node('n:infCarga', $norm) : null;
        $icms = $this->node('n:imp/n:ICMS/*', $inf);
        $toma = $this->text('n:toma3/n:toma', $ide) ?? $this->text('n:toma4/n:toma', $ide);

        return [
            'tipo' => 'cte',
            'ide' => array_filter([
                'Número' => $this->text('n:nCT', $ide),
                'Série' => $this->text('n:serie', $ide),
                'Modelo' => self::code(['57' => '57 – CT-e', '67' => '67 – CT-e OS'], $this->text('n:mod', $ide)),
                'CFOP' => $this->text('n:CFOP', $ide),
                'Natureza da operação' => $this->text('n:natOp', $ide),
                'Tipo do CT-e' => self::code(['0' => 'Normal', '1' => 'Complemento de valores', '2' => 'Anulação', '3' => 'Substituto'], $this->text('n:tpCTe', $ide)),
                'Tipo de serviço' => self::code(['0' => 'Normal', '1' => 'Subcontratação', '2' => 'Redespacho', '3' => 'Redespacho intermediário', '4' => 'Serviço vinculado a multimodal', '6' => 'Transporte de pessoas', '7' => 'Transporte de valores', '8' => 'Excesso de bagagem'], $this->text('n:tpServ', $ide)),
                'Modal' => self::code(['01' => 'Rodoviário', '02' => 'Aéreo', '03' => 'Aquaviário', '04' => 'Ferroviário', '05' => 'Dutoviário', '06' => 'Multimodal'], $this->text('n:modal', $ide)),
                'Emissão' => $this->text('n:dhEmi', $ide),
                'Origem' => trim(($this->text('n:xMunIni', $ide) ?? '').'/'.($this->text('n:UFIni', $ide) ?? ''), '/') ?: null,
                'Destino' => trim(($this->text('n:xMunFim', $ide) ?? '').'/'.($this->text('n:UFFim', $ide) ?? ''), '/') ?: null,
                'Tomador do serviço' => self::code(['0' => 'Remetente', '1' => 'Expedidor', '2' => 'Recebedor', '3' => 'Destinatário', '4' => 'Outros'], $toma),
                'Forma de emissão' => self::code(['1' => 'Normal', '4' => 'Contingência EPEC', '5' => 'Contingência FS-DA', '7' => 'Contingência SVC-RS', '8' => 'Contingência SVC-SP'], $this->text('n:tpEmis', $ide)),
                'Ambiente' => self::code(['1' => 'Produção', '2' => 'Homologação'], $this->text('n:tpAmb', $ide)),
            ]),
            'partes' => array_filter([
                'Emitente (transportadora)' => $this->parte($this->node('n:emit', $inf), 'enderEmit'),
                'Tomador do serviço' => $this->parte($this->node('n:toma4', $ide) ?? $this->node('n:toma', $inf), 'enderToma'),
                'Remetente' => $this->parte($this->node('n:rem', $inf), 'enderReme'),
                'Expedidor' => $this->parte($this->node('n:exped', $inf), 'enderExped'),
                'Recebedor' => $this->parte($this->node('n:receb', $inf), 'enderReceb'),
                'Destinatário' => $this->parte($this->node('n:dest', $inf), 'enderDest'),
            ]),
            'valores' => [
                'total' => $this->text('n:vPrest/n:vTPrest', $inf),
                'receber' => $this->text('n:vPrest/n:vRec', $inf),
                'componentes' => array_map(fn (DOMElement $c) => ['nome' => $this->text('n:xNome', $c), 'valor' => $this->text('n:vComp', $c)], $this->nodes('n:vPrest/n:Comp', $inf)),
            ],
            'icms' => $icms ? array_filter([
                'Situação tributária' => $this->text('n:CST', $icms) ?? ($icms->localName === 'ICMSSN' ? 'Simples Nacional' : null),
                'Base de cálculo' => $this->text('n:vBC', $icms),
                'Redução da base (%)' => $this->text('n:pRedBC', $icms),
                'Alíquota (%)' => $this->text('n:pICMS', $icms),
                'Valor do ICMS' => $this->text('n:vICMS', $icms),
                'Crédito presumido' => $this->text('n:vCred', $icms),
            ]) : [],
            'tributos' => $this->text('n:imp/n:vTotTrib', $inf),
            'carga' => $carga ? [
                'dados' => array_filter([
                    'Valor da carga' => $this->text('n:vCarga', $carga),
                    'Produto predominante' => $this->text('n:proPred', $carga),
                    'Outras características' => $this->text('n:xOutCat', $carga),
                ]),
                'quantidades' => array_map(fn (DOMElement $q) => [
                    'medida' => $this->text('n:tpMed', $q),
                    'quantidade' => $this->text('n:qCarga', $q),
                    'unidade' => self::code(['00' => 'm³', '01' => 'kg', '02' => 't', '03' => 'unidade', '04' => 'litros', '05' => 'MMBTU'], $this->text('n:cUnid', $q)),
                ], $this->nodes('n:infQ', $carga)),
            ] : null,
            'nfes' => array_values(array_filter(array_map(fn (DOMElement $n) => $this->text('n:chave', $n), $norm ? $this->nodes('n:infDoc/n:infNFe', $norm) : []))),
            'outrosDocs' => $norm ? array_map(fn (DOMElement $d) => array_filter([
                'Tipo' => $d->localName === 'infNF' ? 'Nota fiscal modelo 1/1A' : 'Outro documento',
                'Número' => $this->text('n:nDoc', $d),
                'Série' => $this->text('n:serie', $d),
                'Emissão' => $this->text('n:dEmi', $d),
                'Valor' => $this->text('n:vNF', $d) ?? $this->text('n:vDocFisc', $d),
                'Descrição' => $this->text('n:descOutros', $d),
            ]), [...$this->nodes('n:infDoc/n:infNF', $norm), ...$this->nodes('n:infDoc/n:infOutros', $norm)]) : [],
            'modal' => array_filter([
                'RNTRC' => $norm ? $this->text('n:infModal/n:rodo/n:RNTRC', $norm) : null,
            ]),
            'observacoes' => array_filter([
                'Observações' => $this->text('n:compl/n:xObs', $inf),
                'Informações ao Fisco' => $this->text('n:imp/n:infAdFisco', $inf),
            ]),
            'obsCont' => array_map(fn (DOMElement $o) => ['campo' => $o->getAttribute('xCampo'), 'texto' => $this->text('n:xTexto', $o)], $this->nodes('n:compl/n:ObsCont', $inf)),
            'protocolo' => $this->campos($this->node('//n:protCTe/n:infProt'), ['nProt' => 'Protocolo', 'dhRecbto' => 'Autorizado em', 'cStat' => 'Código', 'xMotivo' => 'Situação', 'digVal' => 'Digest']),
            'xml' => $this->pretty($dom),
        ];
    }

    /** @return array<string, string> */
    private function resumo(DOMElement $res): array
    {
        return array_filter([
            'Emitente' => $this->text('n:xNome', $res),
            'CNPJ' => $this->text('n:CNPJ', $res) ?? $this->text('n:CPF', $res),
            'Inscrição estadual' => $this->text('n:IE', $res),
            'Emissão' => $this->text('n:dhEmi', $res),
            'Tipo' => self::code(['0' => 'Entrada', '1' => 'Saída'], $this->text('n:tpNF', $res)),
            'Valor total' => $this->text('n:vNF', $res),
            'Autorizada em' => $this->text('n:dhRecbto', $res),
            'Protocolo' => $this->text('n:nProt', $res),
            'Situação' => self::code(['1' => 'Autorizada', '2' => 'Denegada', '3' => 'Cancelada'], $this->text('n:cSitNFe', $res)),
        ]);
    }

    /**
     * @param  array<string, string>  $map  tag => rótulo
     * @return array<string, string>|null
     */
    private function campos(?DOMElement $node, array $map): ?array
    {
        if (! $node) {
            return null;
        }

        $out = [];
        foreach ($map as $tag => $label) {
            if (($v = $this->text('n:'.$tag, $node)) !== null) {
                $out[$label] = $v;
            }
        }

        return $out ?: null;
    }

    private function node(string $path, ?DOMElement $ctx = null): ?DOMElement
    {
        $n = $this->xp->query($path, $ctx)?->item(0);

        return $n instanceof DOMElement ? $n : null;
    }

    /** @return list<DOMElement> */
    private function nodes(string $path, ?DOMElement $ctx = null): array
    {
        return array_values(array_filter(iterator_to_array($this->xp->query($path, $ctx) ?: []), fn ($n) => $n instanceof DOMElement));
    }

    private function text(string $path, ?DOMElement $ctx): ?string
    {
        if (! $ctx) {
            return null;
        }

        $v = $this->node($path, $ctx)?->textContent;

        return $v === null || trim($v) === '' ? null : trim($v);
    }

    /** @param array<string, string> $map */
    private static function code(array $map, ?string $value): ?string
    {
        return $value === null ? null : ($map[$value] ?? $value);
    }

    private function pretty(DOMDocument $dom): string
    {
        $copy = new DOMDocument;
        $copy->preserveWhiteSpace = false;
        $copy->formatOutput = true;
        $copy->loadXML($dom->saveXML(), LIBXML_NONET);

        return $copy->saveXML($copy->documentElement);
    }
}
