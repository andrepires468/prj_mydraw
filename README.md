# mydraw

Galeria para publicar desenhos feitos com IA. Monorepo com API (Lumen), app (Vue 3) e infraestrutura via Docker Compose; deploy alvo: [Easypanel](https://easypanel.io).

---

## Tecnologias

### API (`apps/mydraw_api`)

| Área | Tecnologia |
| ---- | ---------- |
| Runtime | PHP 8.2, [Lumen 10](https://lumen.laravel.com) |
| Autenticação | [tymon/jwt-auth](https://github.com/tymondesigns/jwt-auth) (access token) |
| Google | [Google Identity Services](https://developers.google.com/identity) (ID Token) — verificação com [firebase/php-jwt](https://github.com/firebase/php-jwt) na API; rotas `POST /api/v1/auth/google`, `…/google/link`, `…/google/unlink` |
| **Object storage (S3)** | [league/flysystem-aws-s3-v3](https://github.com/thephpleague/flysystem-aws-s3-v3) + AWS SDK — API fala com o endpoint S3 (MinIO em dev, bucket S3-compatível em produção) |
| Imagens | GD — geração de thumbnails no upload; originais e derivados vão para o **mesmo bucket** |
| Cache | `illuminate/cache` em arquivo — **cache de listagem de posts** com versionamento (ver [Desempenho](#desempenho)) |
| Banco | MySQL 8 (Eloquent) |

### App (`apps/mydraw_app`)

| Área | Tecnologia |
| ---- | ---------- |
| UI | [Vue 3](https://vuejs.org) (Composition API, `<script setup>`), [TypeScript](https://www.typescriptlang.org) |
| Build | [Vite 8](https://vitejs.dev) |
| Estado / rotas | [Pinia](https://pinia.vuejs.org), [vue-router 4](https://router.vuejs.org) |
| HTTP | [Axios](https://axios-http.com) |
| Estilo | [Tailwind CSS 3](https://tailwindcss.com) |
| Testes E2E | [Playwright](https://playwright.dev) |
| **Login Google** | Google Identity Services no browser (`APP_VITE_GOOGLE_CLIENT_ID`); o **mesmo** Web Client ID deve estar em `GOOGLE_CLIENT_ID` na API para validar o audience do ID Token |

### Infra (Docker Compose)

- **Nginx** — proxy da API  
- **PHP-FPM 8.2** — API  
- **Node 20** — dev server do frontend  
- **MySQL 8** — perfil `local-db` (opcional)  
- **MinIO** — perfil `local-storage`: **S3 compatível** para desenvolvimento local (mesmo protocolo e SDK que produção)

---

## Armazenamento S3 (ênfase)

- **Todo arquivo público da galeria** (imagem original dos posts e **thumbnails**) reside em um **bucket S3-compatível** (MinIO local ou serviço gerenciado).
- A API usa o disco `s3` (Flysystem): uploads multipart, delete em cascata (original + thumbs), URLs públicas ou pré-assinadas conforme configuração.
- Variáveis principais na raiz (`.env.example`): `MINIO_API_URL` (endpoint que a API usa dentro da rede), `MINIO_BUCKET`, `APP_VITE_MINIO_PUBLIC_URL` (base que o **browser** usa para montar URLs de leitura), `MINIO_BUCKET_PUBLIC` (URLs diretas ao bucket vs pré-assinadas).
- **Nunca** use `http://localhost:9100` em `MINIO_API_URL` quando a API roda **dentro do container** — use o hostname do serviço (`minio:9000`) ou o endpoint HTTPS remoto.
- Padrão de keys: originais em `posts/<userId>/<postId>/…`; thumbs derivadas no mesmo bucket com sufixo `-thumb-<W>x<H>.<ext>` (ver abaixo).

---

## Thumbnails — dimensões e regra de geração

Definidas em `PostStorageService::THUMB_SIZES` / qualidade em `THUMB_QUALITY`:

| Derivado | Tamanho máximo (caixa) | Observação |
| -------- | ---------------------- | ---------- |
| **320×320** | largura e altura máximas 320 px | Encaixe **mantendo proporção**; sem distorção; **sem upscale** (se a original já for menor, o thumb não aumenta). |
| **180×180** | idem, 180 px | Ideal para grades densas / cards. |

- Qualidade JPEG/WebP padrão: **85** (PNG/GIF preservam transparência; compressão mapeada conforme implementação).
- A API expõe, por imagem da galeria, um mapa `thumbs` com chaves `320x320` e `180x180` e URLs resolvidas via `StorageObjectUrl` — o frontend deve preferir **thumbs na listagem** e abrir o original no detalhe/modal para melhor **performance**.

---

## Desempenho

- **API — listagem de posts** (`GET /api/v1/posts`): resposta cacheada por poucos segundos (`POSTS_LIST_CACHE_TTL_SECONDS`, padrão **15**). A chave de cache inclui versões globais e por usuário (`PostListCache`): qualquer publicação, edição ou upload **invalida** o cache via *bump* de versão, evitando dados stale sem abandonar o ganho de throughput.
- **App (frontend) — cache em `localStorage`**: a galeria (`GalleryView.vue`) guarda a **primeira página** da listagem por combinação de aba (publicado/rascunho) e ordenação (`mydraw:gallery-cache:v2:…`), para **hidratar** a UI ao reabrir o site enquanto uma nova requisição atualiza os dados. Preferências como ordenação da galeria e tema também persistem no browser.
- **Imagens no app**: uso de thumbs **180×180** / **320×320** nas grades reduz banda e tempo de decode em relação ao original; rotas públicas limitam o que precisa autenticação.
- **E2E**: testes Playwright podem mockar a API — não exigem MinIO ligado para validar layout e fluxos básicos.

---

## Requisitos

- [Docker](https://docs.docker.com/get-docker/) 24+
- [Docker Compose](https://docs.docker.com/compose/) v2+
- Git

---

## Estrutura

```text
prj_mydraw/
├── apps/
│   ├── mydraw_api/          # Lumen + JWT + S3 + posts/galeria
│   └── mydraw_app/          # Vue 3 + Vite + Pinia
├── docker/
│   ├── api/
│   ├── app/
│   └── nginx/
├── scripts/
│   └── init.sh
├── docker-compose.yml
├── .env.example
└── README.md
```

---

## Setup inicial

1. Clone e entre na pasta:

   ```bash
   git clone <repo-url> mydraw
   cd mydraw
   ```

2. Copie `.env.example` para `.env` na **raiz** (única fonte para Compose, API e `VITE_*` do Vue — não há `.env` em `apps/mydraw_app`):

   ```bash
   cp .env.example .env
   ```

3. Bootstrap (se ainda não existir o scaffold em `apps/`):

   ```bash
   bash scripts/init.sh
   ```

4. **Google Sign-In (opcional):** preencha o mesmo Web Client ID em `GOOGLE_CLIENT_ID` (API) e `APP_VITE_GOOGLE_CLIENT_ID` (frontend); reinicie API e app.

5. Suba o ambiente (os perfis vêm de `COMPOSE_PROFILES` no `.env`):

   ```bash
   docker compose up -d --build
   ```

   Para ativar só o perfil MinIO local na linha de comando:  
   `docker compose --profile local-storage up -d --build`

6. Acesse:

   | Serviço | URL |
   | ------- | --- |
   | Frontend (Vite) | http://localhost:5173 |
   | API | http://localhost:8088 |
   | MinIO Console | http://localhost:9101 *(profile `local-storage`)* |
   | MySQL (host) | localhost:33306 *(profile `local-db`)* |

---

## Rotas da API (resumo)

Prefixo base: **`/api/v1`**.

| Área | Exemplos |
| ---- | -------- |
| Saúde | `GET /ping` |
| Auth | `POST /auth/register`, `POST /auth/login`, `POST /auth/google`; com JWT: `GET /auth/me`, `PUT /auth/profile`, `PUT /auth/password`, `POST /auth/google/link`, `POST /auth/google/unlink`, `POST /auth/logout` |
| Posts (leitura; JWT opcional para personalização) | `GET /posts`, `GET /posts/{id}` |
| Posts (escrita) | `PUT/DELETE /posts/{id}`, upload/reordenar/remover imagens |
| Criação | `POST /create-post/…` (JWT) |
| Desenhos (legado / paralelo) | `GET /drawings`, `GET /drawings/{id}`; escrita com JWT |

---

## Páginas do app (visão geral)

- Galeria publicada, rascunhos (autenticado), post em modal ou em `/posts/:id`, criação/edição de posts, login/registro, **Sign in with Google** (com Client ID), perfil (nickname, link Google, alteração de senha).

Rotas públicas incluem galeria publicada, visualização de post e login; criar post, rascunhos e perfil exigem sessão.

---

## Comandos úteis (Docker Compose)

Na raiz do monorepo (onde está `docker-compose.yml`):

```bash
bash scripts/init.sh                                    # bootstrap Lumen + Vue em apps/

docker compose up -d --build                            # sobe stack (perfis do .env)
docker compose --profile local-storage up -d --build    # força perfil MinIO local

docker compose --profile local-storage --profile local-db down
docker compose ps
docker compose logs -f --tail=200

docker compose exec api bash      # shell na API (ou `sh` se não houver bash)
docker compose exec app sh
docker compose exec -it mysql bash

docker compose exec api php artisan migrate
docker compose exec api composer require nome/pacote
docker compose exec app npm install
docker compose exec app npm run test:e2e                # E2E dentro do container (requer browsers instalados)

# Remove volumes locais (MinIO/MySQL) — apaga dados:
docker compose --profile local-storage --profile local-db down -v --remove-orphans
```

Para Playwright no **host**, prefira `cd apps/mydraw_app && npm run test:e2e` (ver secção E2E abaixo).

---

## Testes E2E (Playwright)

Ver `apps/mydraw_app/playwright.config.ts` e `e2e/fluidity.spec.ts`. Instalação típica:

```bash
cd apps/mydraw_app
npm install
npx playwright install chromium
npm run test:e2e
```

---

## MinIO remoto vs. local

- **Remoto (padrão em alguns setups):** defina `MINIO_API_URL` com o endpoint HTTPS que o **container da API** alcança; `APP_VITE_MINIO_PUBLIC_URL` para URLs que o browser carrega. O profile `local-storage` pode ficar desligado.
- **Local (dev):** `COMPOSE_PROFILES` inclui `local-storage`; com `MINIO_API_URL` vazio o compose usa `http://minio:9000` na rede Docker; no host, console em `http://localhost:9101` (portas no `.env.example`).
- Bucket criado pelo serviço `createbuckets` quando o profile está ativo.

Detalhes extras de variáveis (`MINIO_BUCKET_PUBLIC`, credenciais, `AWS_VERIFY_SSL`) estão comentados no `.env.example`.

---

## Deploy (Easypanel)

Serviços do compose podem ser mapeados para apps no Easypanel (API, frontend estático, MySQL gerenciado, **object storage S3** ou MinIO). Replique as variáveis do `.env.example` no painel.

---

## Variável de cache da listagem

| Variável | Descrição |
| -------- | --------- |
| `POSTS_LIST_CACHE_TTL_SECONDS` | TTL em segundos para `GET /posts` (0 desliga cache em memória/arquivo). Padrão típico: `15`. |
