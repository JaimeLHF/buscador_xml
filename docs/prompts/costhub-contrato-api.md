# Contrato da API FinderXML → Costhub

Texto para colar na sessão do Costhub. Atualizado em 30/10/2026. Substitui o contrato enviado antes. Mudanças desde 29/10:

- A chave agora é de UMA empresa (conexão criada no painel admin do FinderXML). Ela só enxerga as notas daquela empresa (item 1).
- Nova rota `GET /api/v1/conexao` para confirmar a conexão quando a chave é colada (item 1.1).
- `empresa_cnpj` passou a ser opcional em `/documents` e em `/cobertura` (itens 2 e 4).
- Continua valendo: status `importada` em `chaves_sped` da `/cobertura` (item 4).

---

Contexto: o Costhub lê as NF-e de compra dos clientes só pela API de leitura do FinderXML (`/api/v1`). Não escreve nada lá. Abaixo está o contrato final para construir o conector.

## 1. Autenticação

- Header `Authorization: Bearer bxml_...` (chave da conexão, só leitura).
- Cada chave é de uma empresa: o operador do FinderXML cria a conexão no painel admin, escolhe a empresa (ex.: MOVEIS RUIZ) e entrega a chave. No Costhub, o cliente cola a chave na conexão com o FinderXML.
- Tudo é restrito à empresa da chave. Pedir o CNPJ de outra empresa devolve lista vazia, nunca erro e nunca dado alheio.
- Chave ausente, errada ou revogada: HTTP 401. Quando o FinderXML gera uma chave nova para a mesma conexão, a anterior passa a dar 401 na hora: o Costhub deve mostrar "conexão revogada, cole a chave nova".
- Cada chamada fica registrada no FinderXML (rota, chave da nota, quantidade, status, IP).

### 1.1 GET /api/v1/conexao

Chame ao salvar a chave, para validar e mostrar a qual empresa ela ficou ligada ("Conectado à MOVEIS RUIZ"):

```json
{
  "data": {
    "conexao": "Costhub",
    "criada_em": "2026-10-30T12:09:40+00:00",
    "escopo": "empresa",
    "empresas": [
      { "cnpj": "24287808000160", "razao_social": "RUIZ INDUSTRIA E COMERCIO DE MOVEIS LTDA", "nome_fantasia": "MOVEIS RUIZ", "uf": "SC" }
    ]
  }
}
```

- `escopo = empresa`: a lista `empresas` tem exatamente uma. Guarde o `cnpj` para associar a conexão à empresa certa no Costhub e recuse se não bater com o CNPJ do cliente.
- `escopo = organizacao`: chave antiga, de antes das conexões, que vale para várias empresas. Não deve aparecer nas conexões novas.

## 2. GET /api/v1/documents

Parâmetros, todos opcionais:

| Parâmetro | Formato | Observação |
|---|---|---|
| `cursor` | string | o `cursor_retomada` (ou `proximo_cursor`) da resposta anterior |
| `alterados_desde` | data ISO 8601 | continua funcionando |
| `tipo` | `nfe` \| `cte` | o Costhub deve mandar `nfe` |
| `direcao` | `entrada` \| `saida` | compra = `entrada` |
| `empresa_cnpj` | 14 dígitos, sem pontuação | opcional: a chave já define a empresa. Dígito verificador validado (422 se inválido); CNPJ de outra empresa dá lista vazia |
| `situacao` | `autorizada` \| `cancelada` \| `denegada` | |
| `xml` | `completo` \| `resumo` | |
| `de`, `ate` | `AAAA-MM-DD` | data de emissão, horário de Brasília |
| `busca` | texto até 100 | fornecedor ou trecho de chave |
| `por_pagina` | 1 a 100 | padrão 50 |

Regras do feed:

- Ordem fixa por `updated_at`, depois `id`.
- `cursor_retomada` vem em TODA resposta, inclusive na última página. Guarde-o ao fim de cada rodada e mande de volta como `?cursor=` na próxima. Página vazia devolve o mesmo cursor recebido; sem cursor recebido e sem dados, vem `null`.
- `proximo_cursor` continua existindo: é `null` na última página.
- Janela de assentamento: só são entregues notas com `updated_at` de mais de 120 segundos atrás. Uma nota recém-gravada aparece na rodada seguinte. Isso evita perder linhas de transações que fazem commit depois da leitura.
- Uma nota já lida volta no feed quando muda (cancelamento, conferência com o SPED, chegada do XML completo). O Costhub deve fazer upsert pela `chave` e aplicar a `situacao` nova.
- `direcao = saida` é a nota emitida pela própria empresa (o modo contador da SEF-SC traz as duas). Para compras, filtre `direcao=entrada`.

Exemplo de resposta:

```json
{
  "data": [
    {
      "chave": "42261011222333000181550010000001231123456788",
      "tipo": "nfe",
      "empresa_cnpj": "12345678000195",
      "emitente_cnpj": "11222333000181",
      "emitente_nome": "FORNECEDOR EXEMPLO LTDA",
      "direcao": "entrada",
      "data_emissao": "2026-10-20T13:15:00+00:00",
      "valor_total": "1500.75",
      "situacao": "autorizada",
      "manifestacao": null,
      "xml_completo": true,
      "xml_reconstruido": false,
      "conferencia_sped": null,
      "conferencia_sped_detalhe": null,
      "atualizado_em": "2026-10-29T14:00:00+00:00"
    }
  ],
  "proximo_cursor": null,
  "cursor_retomada": "eyJ1cGRhdGVkX2F0IjoiMjAyNi0xMC0yOVQxNDowMDowMC4wMDAwMDBaIiwiaWQiOjEyMywiX3BvaW50c1RvTmV4dEl0ZW1zIjp0cnVlfQ"
}
```

Campos que pedem atenção:

- `xml_reconstruido = true`: nota antiga (fora dos ~90 dias da SEFAZ) com XML completo em dados e protocolo, mas sem a assinatura digital do emitente. Serve para análise; não é o original fiscal. Se o XML da SEFAZ chegar depois, ele substitui e o campo volta a `false`.
- `conferencia_sped`: só para as reconstruídas. `confere` (valor e data batem com o C100 do SPED), `diverge` (o motivo vem em `conferencia_sped_detalhe`) ou `null` (sem dados do SPED para conferir).
- `valor_total` vem como string decimal com ponto.
- Datas em ISO 8601 UTC; converta para America/Sao_Paulo para exibir.

## 3. GET /api/v1/documents/{chave} e /xml

- `GET /api/v1/documents/{chave}`: o mesmo objeto do item 2, mais `eventos`:

```json
{
  "data": {
    "chave": "42261011222333000181550010000001231123456788",
    "...": "mesmos campos do item 2",
    "eventos": [
      { "tipo": "110111", "descricao": "Cancelamento", "sequencia": 1, "data": "2026-10-20T18:00:00+00:00", "protocolo": "142260000000001", "direcao": "recebido" }
    ]
  }
}
```

- `GET /api/v1/documents/{chave}/xml`: o XML bruto (`Content-Type: application/xml`). 404 se a chave não existir na organização ou não tiver XML. Cada download fica na auditoria do FinderXML.

## 4. GET /api/v1/cobertura (monitoramento)

`GET /api/v1/cobertura?de=2026-09&ate=2026-10`

- `de` e `ate` obrigatórios, em `AAAA-MM`, `ate >= de`, no máximo 36 meses (senão 422).
- `empresa_cnpj` opcional com chave de empresa (usa a da chave); obrigatório só nas chaves antigas de organização.
- Um bloco por mês do intervalo, mesmo vazio. O mês é o da emissão em America/Sao_Paulo. Chave do SPED sem nota usa a data do C100; sem ela, o AAMM da própria chave, contado em `mes_pela_chave`.
- CNPJ de outra empresa que não a da chave: `"data": []`.

```json
{
  "empresa_cnpj": "12345678000195",
  "data": [
    {
      "mes": "2026-10",
      "chaves_sped": {
        "total": 4,
        "importada": 1,
        "pendente": 0,
        "encontrada": 2,
        "nao_encontrada": 1,
        "erro": 0,
        "mes_pela_chave": 1
      },
      "notas_entrada": { "total": 3, "sefaz": 2, "reconstruidas": 1 },
      "conferencia": { "confere": 0, "diverge": 1, "sem_conferencia": 0 },
      "fora_do_sped": 1
    }
  ]
}
```

O que cada número quer dizer:

- `chaves_sped`: chaves de NF-e de entrada de terceiros lidas do SPED. Cada chave cai em exatamente um status, então `importada + pendente + encontrada + nao_encontrada + erro = total`. `mes_pela_chave` não entra na soma: é só quantas foram posicionadas pelo AAMM da chave.
  - `importada` (NOVO): está no SPED e aguarda alguém escolher se vale a consulta completa (paga). Nada é consultado sozinho.
  - `pendente`: marcada e na fila da consulta.
  - `encontrada`: tem XML completo, vindo da SEFAZ ou da consulta completa.
  - `nao_encontrada`: a consulta completa não achou a nota.
  - `erro`: falhou 3 vezes.
- `notas_entrada`: NF-e de entrada com XML completo, de qualquer situação. `sefaz` (assinada) + `reconstruidas` = `total`.
- `conferencia`: só das reconstruídas; a soma fecha com `reconstruidas`.
- `fora_do_sped`: notas de entrada recebidas cuja chave não está no SPED da empresa.

Atenção: se o Costhub validar a soma dos status contra `total`, inclua `importada`. Sem ele a soma não fecha.

## 5. Recomendação para o conector

1. Ao salvar a chave: `GET /conexao`, confirmar o CNPJ e guardar a chave cifrada.
2. Rodada de hora em hora por conexão: `GET /documents?tipo=nfe&direcao=entrada&cursor=<cursor_retomada salvo>`, seguindo `proximo_cursor` até `null`.
3. Upsert pela `chave`; atualizar situação, `xml_reconstruido` e conferência quando a nota voltar.
4. Baixar o XML só quando `xml_completo = true`, pelo `/xml`.
5. Salvar o `cursor_retomada` da última resposta só depois de gravar a página.
6. Em 401, marcar a conexão como revogada e pedir a chave nova; não tentar de novo em loop.
7. Usar `/cobertura` num painel de monitoramento, não na sincronização.
