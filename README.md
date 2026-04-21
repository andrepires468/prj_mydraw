# mydraw

Galeria para postar desenhos feitos com IA.

Monorepo com:

- **`apps/mydraw_api`** — API em [Lumen 10](https://lumen.laravel.com) + [tymon/jwt-auth](https://github.com/tymondesigns/jwt-auth)
- **`apps/mydraw_app`** — Frontend em [Vue 3](https://vuejs.org) + [Vite](https://vitejs.dev) (hot reload)

Infra local via Docker Compose:

- **MySQL 8** — banco de dados (externo)
- **MinIO** — object storage (S3-compatível) para os desenhos. Por padrão usa um MinIO **remoto**; um container local está disponível via profile `local-storage` (ver seção [MinIO: remoto vs. local](#minio-remoto-vs-local))
- **Nginx** — reverse proxy para a API
- **PHP 8.2 FPM** — runtime da API
- **Node 20** — runtime do frontend

Deploy alvo: VPS com [Easypanel](https://easypanel.io).

---

## Requisitos

- [Docker](https://docs.docker.com/get-docker/) 24+
- [Docker Compose](https://docs.docker.com/compose/) v2+
- Git
- `make` (opcional, mas recomendado)

---

## Estrutura

```text
prj_mydraw/
├── apps/
│   ├── mydraw_api/          # Lumen 10 + JWT (gerado pelo init)
│   └── mydraw_app/          # Vue 3 + Vite     (gerado pelo init)
├── docker/
│   ├── api/                 # Dockerfile + php.ini da API
│   ├── app/                 # Dockerfile do frontend
│   └── nginx/               # Configuração do Nginx
├── scripts/
│   └── init.sh              # Bootstrap do Lumen + Vue 3
├── docker-compose.yml
├── Makefile
├── .env.example
└── README.md
```

---

## Setup inicial

1. Clone o repositório e entre na pasta:

   ```bash
   git clone <repo-url> mydraw
   cd mydraw
   ```

2. Copie o `.env.example` para `.env` e ajuste se necessário:

   ```bash
   cp .env.example .env
   ```

3. Rode o bootstrap (cria Lumen + jwt-auth + Vue 3 + Vite dentro de `apps/`):

   ```bash
   make init
   # ou, se não tiver make:
   # bash scripts/init.sh
   ```

4. Suba o ambiente:

   ```bash
   # Padrão: usa o MinIO REMOTO definido em MINIO_API_URL no .env
   make up

   # Opcional: usa um MinIO LOCAL em container (dev offline / testes)
   make up-local
   ```

5. Acesse:

   | Serviço         | URL                            |
   | --------------- | ------------------------------ |
   | Frontend (Vite) | http://localhost:5173          |
   | API (Lumen)     | http://localhost:8088          |
   | MinIO Console   | http://localhost:9001 *(só com `make up-local`)* |
   | MySQL           | localhost:33306                |

   > As portas padrão `8080` / `3306` costumam estar ocupadas no host
   > (Tomcat, MySQL local, etc.), então o `.env.example` já vem com portas
   > alternativas (`8088` e `33306`). Ajuste conforme necessário.

### Rotas da API já configuradas

| Método         | Rota                       | Proteção | Descrição                                        |
| -------------- | -------------------------- | -------- | ------------------------------------------------ |
| GET            | `/`                        | pública  | Meta/healthcheck raiz                            |
| GET            | `/api/ping`                | pública  | Smoke test (timestamp, env)                      |
| POST           | `/api/auth/register`       | pública  | Cria usuário e devolve JWT                       |
| POST           | `/api/auth/login`          | pública  | Devolve JWT para credenciais válidas             |
| GET            | `/api/auth/me`             | JWT      | Dados do usuário autenticado                     |
| POST           | `/api/auth/logout`         | JWT      | Invalida o token atual                           |
| GET            | `/api/drawings`            | pública  | Listagem paginada de desenhos                    |
| GET            | `/api/drawings/{id}`       | pública  | Detalhe de um desenho                            |
| POST           | `/api/drawings`            | JWT      | Upload de desenho (multipart) — salva no MinIO   |
| DELETE         | `/api/drawings/{id}`       | JWT      | Remove desenho próprio (DB + MinIO)              |

### Páginas do APP

- `/` — home com health check da API
- `/gallery` — lista da galeria consumindo `GET /api/drawings`

---

## Comandos úteis (Makefile)

```bash
make init        # bootstrap inicial do Lumen + Vue
make up          # sobe serviços usando MinIO remoto (.env)
make up-local    # sobe serviços + MinIO local (profile local-storage)
make down        # derruba todos os serviços (incl. MinIO local)
make logs        # segue os logs de todos os serviços
make ps          # lista containers
make api-sh      # abre shell no container da API
make app-sh      # abre shell no container do APP
make mysql-sh    # abre o client mysql dentro do container
make artisan ARGS="migrate"   # roda artisan na API
make composer ARGS="require pkg/name"  # roda composer na API
make npm ARGS="install axios"          # roda npm no APP
make clean       # remove volumes e containers (cuidado!)
```

---

## MinIO: remoto vs. local

O projeto consome um MinIO externo por padrão — os serviços `minio` e
`createbuckets` do `docker-compose.yml` ficam sob o profile
`local-storage` e só sobem quando explicitamente solicitados.

### Usar o MinIO remoto (padrão)

No `.env` raiz, preencha:

```env
MINIO_API_URL=https://minio.seu-dominio.com        # endpoint S3
APP_VITE_MINIO_PUBLIC_URL=https://minio.seu-dominio.com
MINIO_ROOT_USER=...           # access key
MINIO_ROOT_PASSWORD=...       # secret key
MINIO_BUCKET=mydraw-drawings
```

E suba normalmente:

```bash
make up
```

O container local de MinIO **não** será iniciado — as portas `9000` e
`9001` ficam livres no host.

### Usar o MinIO local (dev offline / testes isolados)

1. No `.env`, deixe `MINIO_API_URL` vazio (cai no fallback
   `http://minio:9000`, que é o hostname do container) e use
   `APP_VITE_MINIO_PUBLIC_URL=http://localhost:9000`.
2. Suba com o profile ativado:

   ```bash
   make up-local
   # equivalente a: docker compose --profile local-storage up -d --build
   ```

3. O bucket é criado automaticamente pelo serviço `createbuckets` e o
   console fica em http://localhost:9001 (usuário/senha =
   `MINIO_ROOT_USER` / `MINIO_ROOT_PASSWORD`).

### Alternar entre os dois modos

```bash
make down        # derruba tudo, incluindo MinIO local, se estiver rodando
# ajuste o .env
make up          # ou `make up-local`
```

---

## Fluxo de desenvolvimento

- **API**: código em `apps/mydraw_api/`. O container monta essa pasta como volume, então qualquer alteração reflete imediatamente.
- **APP**: código em `apps/mydraw_app/`. O `vite dev` roda dentro do container com `--host 0.0.0.0` e HMR exposto na porta `5173`.
- **MinIO**: por padrão o projeto fala com um MinIO **remoto** (via `MINIO_API_URL`). Se usar `make up-local`, o profile `local-storage` sobe um MinIO em container e cria o bucket (`MINIO_BUCKET`) automaticamente via `createbuckets`.
- **MySQL**: usado externamente; o compose não sobe instância de MySQL.

---

## Deploy (Easypanel)

Cada serviço deste compose pode ser mapeado 1:1 em um app do Easypanel:

1. **API**: use o `docker/api/Dockerfile` + montagem do volume da aplicação + serviço MySQL gerenciado.
2. **APP**: build de produção (`npm run build`) servido por Nginx — crie um `Dockerfile.prod` quando estiver próximo do deploy.
3. **MinIO**: já tem template pronto no Easypanel.
4. **MySQL**: use o template oficial.

Variáveis de ambiente devem ser configuradas no painel do Easypanel. O `.env.example` serve como referência.

---

## Próximos passos (após esse setup)

- [ ] Configurar rotas de autenticação JWT (`/auth/register`, `/auth/login`)
- [ ] Modelo de `User` + migration
- [ ] Modelo de `Drawing` + upload para MinIO
- [ ] Layout inicial da galeria no frontend
- [ ] Pipeline CI/CD para Easypanel
