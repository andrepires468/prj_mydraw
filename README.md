# mydraw

Galeria para postar desenhos feitos com IA.

Monorepo com:

- **`apps/mydraw_api`** — API em [Lumen 10](https://lumen.laravel.com) + [tymon/jwt-auth](https://github.com/tymondesigns/jwt-auth)
- **`apps/mydraw_app`** — Frontend em [Vue 3](https://vuejs.org) + [Vite](https://vitejs.dev) (hot reload)

Infra local via Docker Compose:

- **MySQL 8** — banco de dados
- **MinIO** — object storage (S3-compatível) para os desenhos
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
   make up
   ```

5. Acesse:

   | Serviço         | URL                            |
   | --------------- | ------------------------------ |
   | Frontend (Vite) | http://localhost:5173          |
   | API (Lumen)     | http://localhost:8088          |
   | MinIO Console   | http://localhost:9001          |
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
make up          # sobe todos os serviços em background
make down        # derruba todos os serviços
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

## Fluxo de desenvolvimento

- **API**: código em `apps/mydraw_api/`. O container monta essa pasta como volume, então qualquer alteração reflete imediatamente.
- **APP**: código em `apps/mydraw_app/`. O `vite dev` roda dentro do container com `--host 0.0.0.0` e HMR exposto na porta `5173`.
- **MinIO**: um bucket (`MINIO_BUCKET` no `.env`) é criado automaticamente pelo serviço `createbuckets` no primeiro `up`.
- **MySQL**: os dados persistem no volume `mydraw_mysql_data`.

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
