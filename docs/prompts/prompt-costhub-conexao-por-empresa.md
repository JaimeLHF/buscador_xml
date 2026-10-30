# Prompt: Costhub com chave do FinderXML por empresa

Prompt para colar na sessão do Claude do Costhub (`~/projetos/DataPrim`). Escrito em 30/10/2026. Junto com ele, cole o contrato atualizado em `docs/prompts/costhub-contrato-api.md` do FinderXML.

---

Contexto: o FinderXML mudou a forma de dar acesso à API `/api/v1`. Antes era UMA chave `bxml_` da organização, que via todas as empresas (por isso o Costhub tem `FINDERXML_TOKEN` no `.env`). Agora cada chave é uma "conexão" de UMA empresa, criada pelo operador no painel admin do FinderXML. A chave da MOVEIS RUIZ só enxerga as notas da MOVEIS RUIZ. O cliente cola essa chave no Costhub, na conexão da empresa dele.

O contrato completo está no texto anexo. Mudanças em relação ao de 29/10:

- Nova rota `GET /api/v1/conexao`: devolve `conexao`, `criada_em`, `escopo` (`empresa` ou `organizacao`) e `empresas` (lista com `cnpj`, `razao_social`, `nome_fantasia`, `uf`). Numa chave de empresa, a lista tem exatamente uma.
- `empresa_cnpj` virou opcional em `/documents` e `/cobertura`: a chave já define a empresa. Mandar o CNPJ da própria empresa continua funcionando; o CNPJ de outra empresa devolve lista vazia.
- Gerar uma chave nova no FinderXML revoga a anterior na hora (HTTP 401).
- Cada chamada fica registrada no FinderXML (rota, chave da nota, quantidade, status, IP).

Leia antes de começar: `backend/app/DataBridge/Services/FinderXmlClient.php`, `backend/app/DataBridge/Connectors/FinderXmlConnector.php`, `backend/app/DataBridge/Connectors/ConnectorFactory.php`, `backend/app/DataBridge/Commands/CompareFinderXmlCommand.php`, o model `ErpConnector` (as `credentials` já são cifradas) e `backend/tests/Feature/DataBridge/FinderXmlConnectorTest.php`.

Faça nesta ordem. Cada item precisa de um teste Pest que FALHE se a regra for removida: quebre o código de propósito, veja o teste falhar e desfaça.

1. Chave por conector, não por `.env`.
   - A chave vem de `ErpConnector->credentials['token']` (cifrada pelo mutator que já existe). O `FinderXmlClient` recebe o token pelo construtor; o `ConnectorFactory` passa o da credencial.
   - `FINDERXML_BASE_URL` continua global no `.env`. `FINDERXML_TOKEN` deixa de ser usado pelo conector. Se o `finderxml:compare` precisar de uma chave, ele recebe o conector (ou a empresa) e usa a chave dele.
   - Nunca logar, exibir nem devolver a chave em resposta de API. Mostre só os 12 primeiros caracteres (`bxml_xxxxxxx…`), como o FinderXML faz.

2. Validar ao salvar: `testConnection()` chama `GET /api/v1/conexao`.
   - 401: chave inválida ou revogada. Mensagem para o usuário: "Chave inválida ou revogada. Peça uma nova ao suporte do FinderXML."
   - `escopo` diferente de `empresa`: recusar ("Esta chave não é de uma empresa").
   - O `cnpj` devolvido precisa ser o da empresa do conector no Costhub. Se não bater, recusar e dizer de qual empresa a chave é ("Esta chave é da EMPRESA X, CNPJ ..."). Isso impede colar a chave de um cliente na empresa de outro.
   - Em caso de sucesso, guardar no `config` do conector `finderxml_empresa` (cnpj e razão social) e `conectado_em`, e mostrar "Conectado à MOVEIS RUIZ" na tela.

3. Sincronização.
   - O feed continua igual (`tipo=nfe`, `direcao=entrada`, cursor salvo só depois de gravar a página). Pode parar de mandar `empresa_cnpj`, ou continuar mandando o da própria empresa; os dois funcionam.
   - `/cobertura` pode ir sem `empresa_cnpj`.
   - 401 no meio da rodada: parar a rodada sem perder o cursor salvo, marcar o conector como revogado (status de erro que a tela já mostre) e não tentar de novo em loop. Quando o cliente colar a chave nova e ela validar (item 2), a sincronização continua do mesmo `cursor_retomada`.

4. Tela de conexão.
   - Campo para colar a chave na conexão FinderXML da empresa, com o retorno do item 2 (empresa conectada ou o erro).
   - Botão "Trocar chave", que valida a nova antes de substituir a antiga.
   - NÃO tire o `finderxml` da trava de `supportedTypes()`. Ligar o FinderXML com o ERP ativo duplica notas. A virada (etapa 2) é decisão do Jaime. Prepare a tela e a validação para funcionarem assim que a trava sair, e diga como testá-las enquanto ela existe.

5. Limpeza.
   - Atualize o comentário de `config/services.php` (a chave não é mais da organização) e remova `FINDERXML_TOKEN` do `.env.example`.
   - Atualize a documentação interna que cite a chave única da organização.

Ao terminar, me devolva: arquivos alterados, como testar localmente contra o FinderXML (`http://localhost:8081`) e o que falta para a etapa 2.
