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
| Falha de rede (SoapException) | Não avança NSU, tenta de novo em 15 min |

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

## Testes

```bash
./vendor/bin/sail pest
```

Os testes usam o banco `testing` do Postgres e **nunca** chamam a SEFAZ real (fixtures XML a partir da Fase 3).
