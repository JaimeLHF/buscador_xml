# Buscador XML

Busca automática de NF-e (e futuramente CT-e) destinadas às empresas cadastradas, via **NFeDistribuicaoDFe** (Ambiente Nacional), com manifestação do destinatário, armazenamento dos XMLs e painel multiempresa.

## Stack

| Item | Versão |
|---|---|
| Laravel | 13.x (starter kit Livewire + Flux + Fortify com 2FA) |
| PHP (container) | 8.4 |
| Banco / filas | PostgreSQL 18, Redis, Laravel Horizon |
| Fiscal | nfephp-org/sped-nfe 5.2, sped-common 5.1, sped-da 1.1 |
| Testes | Pest |
| E-mail dev | Mailpit |

**Por que Livewire:** tudo fica em PHP/Blade, sem uma segunda base de código em JS para um painel que é basicamente CRUD, filtros e tabelas.

`composer.json` fixa `config.platform.php = 8.4`, então o lock sempre bate com o container, mesmo rodando Composer no host (PHP 8.5).

## Instalação no WSL2 (Ubuntu)

Requisitos: Docker Engine com Compose v2 dentro do WSL e o projeto no filesystem Linux (`~/projetos/...`), **nunca** em `/mnt/c`.

```bash
cd ~/projetos/buscador_xml
cp .env.example .env
composer install            # ou: docker run --rm -v "$PWD":/app -w /app composer install
php artisan key:generate
php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"   # cole em CERT_ENCRYPTION_KEY
./vendor/bin/sail up -d --build
./vendor/bin/sail artisan migrate
./vendor/bin/sail npm install && ./vendor/bin/sail npm run build
```

Sugestão: `alias sail='./vendor/bin/sail'`.

### Portas (ajustadas para não colidir com outros serviços locais)

| Serviço | Host |
|---|---|
| App | http://localhost:8081 |
| Vite | 5174 |
| PostgreSQL | 5433 |
| Redis | 6380 |
| Mailpit (UI) | http://localhost:8026 |
| Horizon | http://localhost:8081/horizon |

### Containers

- `laravel.test`: app web
- `horizon`: workers das filas (Redis)
- `scheduler`: `schedule:work` (dispara as sincronizações a cada minuto)
- `pgsql`, `redis`, `mailpit`

O Dockerfile publicado fica em `docker/8.4/`.

## Extensões PHP

Todas presentes na imagem Sail 8.4 e verificadas em `tests/Unit/EnvironmentTest.php`:

`openssl`, `soap`, `curl`, `dom`, `xml`, `zlib`, `mbstring`, `zip`, `gd` (+ `pgsql`, `redis`, `pcntl` para Horizon).

## OpenSSL 3 e certificados A1 legados

Muitos `.pfx` de certificadoras ICP-Brasil são cifrados com **RC2-40/3DES**, que o OpenSSL 3 só lê com o *legacy provider*. O `openssl_pkcs12_read()` do PHP falha com `error:0308010C:digital envelope routines::unsupported`.

Na imagem Ubuntu/Ondřej, o PHP **não** carrega o provider legado mesmo com `OPENSSL_CONF` apontando para uma configuração que o ativa (o binário `openssl` carrega, a extensão do PHP não). Por isso a solução adotada não depende de configuração do OpenSSL no runtime:

1. No upload, tenta `openssl_pkcs12_read()`.
2. Se falhar por algoritmo não suportado, converte com o binário: `openssl pkcs12 -legacy -nodes` (senha via variável de ambiente do processo, nunca em argumento; arquivos temporários com permissão 0600 e removidos em seguida).
3. Regrava o PKCS#12 com `openssl_pkcs12_export()` (AES-256 + PBKDF2) e criptografa o resultado.

Resultado: o certificado armazenado é sempre moderno, e o sped-nfe o lê sem nenhum ajuste no OpenSSL. Em produção basta ter o binário `openssl` 3.x (`OPENSSL_BINARY` no `.env`, se não estiver no PATH).

## Ambiente SEFAZ

`SEFAZ_TP_AMB=2` (homologação) é o padrão. Empresas cadastradas como produção **não** consultam a SEFAZ enquanto `SEFAZ_ALLOW_PRODUCTION=false` (nem são enfileiradas).

## Distribuição DF-e (NT 2014.002)

Fluxo: `scheduler` (a cada minuto) → `sefaz:dispatch-due` → job `SyncCompanyDfe` na fila `sefaz` (Horizon) → `DistribuicaoService`.

| Retorno | Ação |
|---|---|
| 138 | Processa os docZip e segue com o `ultNSU` retornado enquanto `ultNSU < maxNSU`; ao alcançar, espera 65 min |
| 137 | Grava `ultNSU`, espera 65 min |
| 656 | Para, adota o `ultNSU` retornado (se houver), espera 65 min e conta `consecutive_656` |
| Outra rejeição / erro de dados | Espera 65 min |
| Falha que certamente não chegou à SEFAZ (DNS, conexão, TLS, HTTP 403) | Não avança NSU; backoff exponencial 2, 4, 8, 16, 32, 60 min |
| Falha ambígua (timeout, resposta vazia, 5xx) | Pode ter sido processada: espera 65 min, como um 137 |

Garantias:

- `ultNSU` nunca retrocede; documentos e avanço do NSU de cada página ficam na mesma transação.
- Lock atômico por CNPJ+ambiente (`Cache::lock`) e job único por empresa (`ShouldBeUnique`, `tries = 1`).
- A espera de 1 hora é compartilhada por CNPJ (`CnpjCooldown`), mesmo que o CNPJ esteja cadastrado em mais de uma organização.
- `consChNFe`/`consNSU`: janela deslizante de 1 hora no Redis (script Lua atômico), máximo 20 por CNPJ.
- `sefaz_requests` registra serviço, cStat, NSUs, duração e erro — nunca XML, senha ou certificado.
- XMLs gravados exatamente como recebidos em `storage/app/private/dfe/{empresa}/...` (disco `SEFAZ_XML_DISK`, pronto para S3).

Comandos:

```bash
./vendor/bin/sail artisan sefaz:dispatch-due   # o que o scheduler roda
./vendor/bin/sail artisan sefaz:sync 1         # sincroniza a empresa 1 agora (respeita next_check_at e o lock)
```

**Limitação conhecida:** o sped-nfe 5.2.8 ainda valida o `distDFeInt` com XSD que só aceita CNPJ numérico. Empresas com CNPJ alfanumérico registram o erro de validação e aguardam 65 min até a biblioteca ser atualizada.

## Manifestação do destinatário

- `manifestacao_automatica` ligada: ao receber um `resNFe` autorizado e sem manifestação, enfileira a **Ciência da Operação (210210)** depois que a página da distribuição é gravada. Em seguida, tenta o `procNFe` por `consChNFe`, só se o limite de 20/hora do CNPJ permitir; senão, o XML chega na próxima distribuição.
- Confirmação (210200), Desconhecimento (210220) e Operação não Realizada (210240, justificativa de 15 a 255 caracteres) são manuais, na página do documento, com confirmação. Só proprietário e admin.
- Bloqueios: NF-e cancelada, manifestação conclusiva já registrada, Ciência repetida. Duplicidade (573) conta como registrado.
- O evento autorizado é guardado como `procEventoNFe` (assinatura + protocolo).

## Painel

| Tela | Rota |
|---|---|
| Dashboard (indicadores e alertas da organização) | `/dashboard` |
| Empresas, certificado, "Sincronizar agora" | `/companies` |
| Documentos (filtros, XML, DANFE, exportar ZIP) | `/documents` |
| Exportações ZIP (expiram em 7 dias) | `/exports` |
| Log de requisições à SEFAZ | `/sefaz-log` |
| Membros e convites | `/organization/members` |

Alertas por e-mail e no dashboard (só proprietários e admins): certificado vencendo em 30/15/7 dias (todo dia às 08:00) e 3 consumos indevidos (656) seguidos (`SEFAZ_BLOCKED_ALERT_AFTER`).

## Segurança

- **Tenancy:** toda rota e ação Livewire passa por Policy (`CompanyPolicy`, `DfeDocumentPolicy`, `ExportPolicy`) que exige a organização ativa e o vínculo do usuário. Consultas são sempre restritas à organização ativa.
- **Certificados:** `.pfx` e senha criptografados (AES-256-GCM) com `CERT_ENCRYPTION_KEY`, separada da `APP_KEY`, atrás da interface `CertificateVault`. Para usar KMS, troque a implementação no `AppServiceProvider`. Parâmetros sensíveis marcados com `#[\SensitiveParameter]` (não aparecem em stack traces). A chave privada que o sped grava temporariamente para o cURL é cifrada (`setEncriptPrivateKey(true)`).
- **Upload:** fora do Livewire (o arquivo não passa pelo diretório temporário dele), no máximo 64 KB, com validação de senha, validade e raiz do CNPJ.
- **Logs:** `sefaz_requests` e o log da aplicação nunca recebem XML, senha ou certificado. Mensagens de erro têm caminhos temporários removidos (`FailureClassifier::safeMessage`).
- **Arquivos:** XMLs e ZIPs no disco `local` com `serve => false`, entregues só por controllers com Policy. O ZIP só pode ser baixado por quem o pediu.
- **Convites:** só o hash SHA-256 do token fica no banco. O e-mail é enviado de forma síncrona (o token não vai para a fila). Para aceitar, é preciso estar logado com e-mail verificado igual ao convidado, e quem já é membro mantém o papel.
- **HTTP:** headers `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy` e HSTS sob HTTPS. Throttle em upload de certificado (10/min), sincronizar agora (6/min), DANFE (30/min), convites (10/min), exportações (5 a cada 5 min) e consulta de CNPJ (20/min).
- **Horizon:** fora de `local`, só os e-mails de `HORIZON_ADMINS`.

### Rotação da chave dos certificados

```bash
# 1. CERT_ENCRYPTION_PREVIOUS_KEYS=<chave atual>   2. CERT_ENCRYPTION_KEY=<nova chave>
./vendor/bin/sail artisan certificates:reencrypt
# 3. remova CERT_ENCRYPTION_PREVIOUS_KEYS
```

## Produção (checklist)

1. `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true` e HTTPS.

2. `zend.exception_ignore_args=On` no `php.ini`.

3. `CERT_ENCRYPTION_KEY` própria, guardada num cofre de segredos e com backup. Sem ela, os certificados não podem ser lidos.

4. `SEFAZ_ALLOW_PRODUCTION=true` só quando for operar de verdade, com as empresas cadastradas como ambiente "Produção".

5. Processos: `php artisan horizon` (supervisor/systemd), cron `* * * * * php artisan schedule:run`, e `php artisan horizon:terminate` a cada deploy.

6. S3 para os XMLs: `SEFAZ_XML_DISK=s3` + `AWS_*`. Os caminhos já são relativos ao disco.

7. Redis com persistência (AOF): guarda o contador de 20/hora e a espera por CNPJ. Se o Redis for perdido, o `next_check_at` no Postgres continua protegendo a distribuição.

8. Backups do Postgres e do disco de XMLs.

## Variáveis de ambiente

| Variável | Padrão | Uso |
|---|---|---|
| `CERT_ENCRYPTION_KEY` | — | Chave dos certificados (`base64:` + 32 bytes) |
| `CERT_ENCRYPTION_PREVIOUS_KEYS` | vazio | Chaves antigas, durante a rotação |
| `OPENSSL_BINARY` | `openssl` | Binário usado para converter `.pfx` legados |
| `SEFAZ_ALLOW_PRODUCTION` | `false` | Trava de consultas em produção |
| `SEFAZ_WAIT_AFTER_137` / `SEFAZ_WAIT_AFTER_656` | `65` | Minutos de espera da NT |
| `SEFAZ_RETRY_BASE_MINUTES` / `SEFAZ_RETRY_MAX_MINUTES` | `2` / `60` | Backoff para falhas não entregues |
| `SEFAZ_MAX_PAGES` | `20` | Páginas de NSU por execução |
| `SEFAZ_TIMEOUT` | `30` | Timeout SOAP (s) |
| `SEFAZ_BLOCKED_ALERT_AFTER` | `3` | 656 seguidos até o alerta |
| `SEFAZ_XML_DISK` | `local` | Disco dos XMLs e ZIPs |
| `SEFAZ_QUEUE` | `sefaz` | Fila dos jobs da SEFAZ |
| `HORIZON_ADMINS` | vazio | E-mails com acesso ao Horizon fora de `local` |

## Solução de problemas

| Sintoma | Causa provável |
|---|---|
| 656 repetido | Outro sistema (ERP, contador) consulta o mesmo CNPJ com o mesmo certificado. Só um consumidor por CNPJ. |
| 593 / "CNPJ-Base difere do certificado" | Certificado de outra raiz de CNPJ |
| "Senha do certificado incorreta" com senha certa | Arquivo corrompido. Exporte o `.pfx` de novo. |
| Ficam só resumos (`resNFe`) | Manifestação automática desligada, ou Ciência com erro (veja o filtro "Manifestação" na lista) |
| "Consultas em produção estão desabilitadas" | `SEFAZ_ALLOW_PRODUCTION=false` |
| CNPJ alfanumérico falha com "XML não é válido" | Limitação do sped-nfe 5.2.8 (ver acima) |

## Testes

```bash
./vendor/bin/sail pest
./vendor/bin/sail php vendor/bin/phpstan analyse --memory-limit=1G
```

Os testes usam o banco `testing` do Postgres e o Redis DB 15, e **nunca** chamam a SEFAZ real: um `FakeSefazGateway` é registrado em todos os testes de Feature, com fixtures XML (137, 138, 656, resNFe, procNFe, procEventoNFe, resEvento, retEnvEvento).

O único aviso do Larastan (`CompanyValidationRules` "used zero times") é um falso positivo: o trait é usado num componente Livewire de arquivo único, que o analisador não lê.
