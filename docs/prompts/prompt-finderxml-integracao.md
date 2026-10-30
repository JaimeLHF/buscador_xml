# Prompt — integração FinderXML → Costhub

Prompt para colar na sessão do Claude do FinderXML (`~/projetos/buscador_xml`). Escrito em 29/10/2026. Ele pede as quatro peças que faltam na API para o Costhub consumir as NF-e de compra, e o contrato final de volta, que depois vem para a sessão do Costhub.

---

Contexto: o Costhub (repositório DataPrim, Laravel 12 + MySQL) vai usar o FinderXML como ÚNICA fonte de NF-e de compra dos clientes. O Costhub não escreve nada aqui: ele só lê a API `/api/v1` (token `bxml_`, só leitura), normaliza no lado dele e mostra nos painéis. Fluxo:

FinderXML (SEFAZ + Meu Danfe/SPED) -> banco do FinderXML -> GET /api/v1 pelo Costhub -> normalização no Costhub

Hoje a API já tem `GET /documents` (cursor, `alterados_desde`, filtros `tipo`, `situacao`, `xml`, `de`, `ate`, `busca`), `GET /documents/{chave}` e `GET /documents/{chave}/xml`, e o resource já expõe `xml_reconstruido` e `conferencia_sped`. Leia `app/Http/Controllers/Api/DocumentController.php`, `app/Queries/DocumentFilter.php`, `app/Services/MeuDanfe/` e `tests/Feature/NotasHistoricasTest.php` antes de começar.

Faltam quatro coisas. Faça nesta ordem, cada uma com teste Pest que FALHA se a regra for removida (quebre o código de propósito, veja o teste falhar, desfaça):

1. Direção da nota.
   O modo contador da SEF-SC traz notas EMITIDAS pela própria empresa, e o Costhub só analisa COMPRA.
   - Campo `direcao` no resource: `entrada` ou `saida`, a partir de `DfeDocument::emitidaPelaEmpresa()`.
   - Filtro `direcao=entrada|saida` em `GET /documents`.
   - Teste: nota emitida pela empresa sai com `saida` e não aparece com `direcao=entrada`.

2. Filtro por empresa.
   - `empresa_cnpj` (14 dígitos, validado) em `GET /documents`.
   - Empresa que não é da organização do token devolve lista vazia, nunca a nota de outra organização. O teste cobre exatamente isso.

3. Cursor de retomada que não perde nota.
   O Costhub sincroniza de hora em hora e precisa continuar de onde parou, sem perder nem duplicar.
   - A resposta passa a trazer sempre `cursor_retomada`, inclusive na última página. É o ponto em que o cliente guarda e manda de volta na próxima rodada. Página vazia devolve o mesmo cursor recebido.
   - Cuidado com a transação: a distribuição grava uma página inteira numa transação. Uma linha confirmada DEPOIS da leitura pode ter `updated_at` ANTERIOR ao último item lido, e o cursor passaria por cima dela para sempre. Resolva com uma janela de assentamento: a listagem só entrega linhas com `updated_at < now() - 2 minutos` (configurável) e explica isso num comentário.
   - Cancelamento e conferência mudam o `updated_at` e fazem a nota reaparecer no feed: confirme com teste que uma nota já lida volta com `situacao = cancelada` depois do evento de cancelamento.
   - Não remova `alterados_desde` nem `proximo_cursor`: só acrescente.

4. Endpoint de cobertura (monitoramento).
   `GET /api/v1/cobertura?empresa_cnpj=...&de=AAAA-MM&ate=AAAA-MM`, um bloco por mês:
   - `chaves_sped`: total importado do SPED, e por status (`encontrada`, `pendente`, `nao_encontrada`, `erro`).
   - `notas_entrada`: NF-e de entrada com XML completo, separadas em `sefaz` (distribuição, assinada) e `reconstruidas` (Meu Danfe).
   - `conferencia`: `confere`, `diverge`, `sem_conferencia` (das reconstruídas).
   - `fora_do_sped`: notas de entrada recebidas cuja chave não está no SPED daquele mês.
   - O mês é o da emissão em America/Sao_Paulo. Para chave do SPED sem nota, use `sped_data`. Se ela estiver vazia (importada antes da conferência), use o AAMM da chave (posições 3 a 6) e conte isso em `mes_pela_chave`.
   - Cada chave cai em EXATAMENTE UM balde de status. O teste soma os baldes e exige que feche com o total. Divergência aparece, nunca some.
   - Mesmo isolamento por organização do item 2, com teste.

Regras:
- Só leitura na API. Nada de manifestar, buscar ou gravar a partir dela.
- Não mude o fluxo do Meu Danfe nem o da distribuição, a não ser o necessário para o `updated_at`.
- Os testes nunca chamam a SEFAZ nem o Meu Danfe reais (`FakeSefazGateway`, `Http::fake`).
- Rode a suíte inteira, o pint e o phpstan. O phpstan já tem erros antigos em outros arquivos: só não pode surgir erro novo nos arquivos que você tocar.
- A pasta `Arquivos ReceitanetBX/` é SPED real de cliente e está no `.gitignore`. Nunca a leia em teste nem a commite.
- Commit em inglês, no estilo dos commits existentes. NÃO faça push: a main sobe direto para produção. Pergunte antes.

Ao terminar, me devolva:
1. O contrato final da API: cada rota, parâmetros e um exemplo de JSON de resposta, inclusive `cursor_retomada` e `/cobertura`.
2. Os arquivos alterados, com uma linha cada.
3. O SHA do commit.
Vou colar esse contrato na sessão do Costhub para construir o conector.
