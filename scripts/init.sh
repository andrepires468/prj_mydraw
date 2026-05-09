#!/usr/bin/env bash
# ==============================================================================
# mydraw — bootstrap inicial do monorepo
#
# O que este script faz:
#   1) Garante o arquivo .env
#   2) Dentro de um container PHP 8.2 temporário:
#        - cria o projeto Lumen 10 em apps/mydraw_api (se ainda não existir)
#        - instala tymon/jwt-auth
#        - instala league/flysystem-aws-s3-v3 (para MinIO)
#        - gera o JWT_SECRET
#        - publica o config do jwt
#        - copia o .env.example da API
#   3) Dentro de um container Node 20 temporário:
#        - cria o projeto Vue 3 + TS em apps/mydraw_app (se ainda não existir)
#        - instala axios + pinia + vue-router
#        - ajusta vite.config para expor em 0.0.0.0
# ==============================================================================

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

API_DIR="apps/mydraw_api"
APP_DIR="apps/mydraw_app"

log()  { printf "\033[1;36m▶ %s\033[0m\n" "$*"; }
ok()   { printf "\033[1;32m✔ %s\033[0m\n" "$*"; }
warn() { printf "\033[1;33m! %s\033[0m\n" "$*"; }

# ------------------------------------------------------------------------------
# 1) .env
# ------------------------------------------------------------------------------
if [ ! -f .env ]; then
  log "Criando .env a partir de .env.example"
  cp .env.example .env
  ok ".env criado"
else
  ok ".env já existe"
fi

# shellcheck disable=SC1091
set -a; . ./.env; set +a

mkdir -p "$API_DIR" "$APP_DIR"

# ------------------------------------------------------------------------------
# 2) API — Lumen 10 + jwt-auth
# ------------------------------------------------------------------------------
if [ ! -f "$API_DIR/composer.json" ]; then
  log "Criando projeto Lumen 10 em $API_DIR"
  docker run --rm \
    -v "$ROOT_DIR/$API_DIR":/app \
    -w /app \
    composer:2 \
    create-project --prefer-dist laravel/lumen:^10.0 . --no-interaction
  ok "Lumen 10 criado"
else
  warn "$API_DIR já possui composer.json — pulando create-project"
fi

log "Instalando dependências da API (jwt-auth + flysystem S3)"
docker run --rm \
  -v "$ROOT_DIR/$API_DIR":/app \
  -w /app \
  composer:2 \
  require \
    tymon/jwt-auth:^2.1 \
    league/flysystem-aws-s3-v3:^3.0 \
    --no-interaction
ok "Dependências PHP instaladas"

# .env da API (Lumen usa .env na raiz do projeto Lumen, não na raiz do monorepo)
if [ ! -f "$API_DIR/.env" ]; then
  if [ -f "$API_DIR/.env.example" ]; then
    cp "$API_DIR/.env.example" "$API_DIR/.env"
  else
    touch "$API_DIR/.env"
  fi
fi

# Gera JWT_SECRET (32 bytes em base64url) se ainda não existir no .env raiz
if grep -q '^API_JWT_SECRET=CHANGE_ME_AFTER_INIT' .env 2>/dev/null || \
   ! grep -q '^API_JWT_SECRET=' .env 2>/dev/null; then
  JWT_SECRET_VALUE="$(openssl rand -base64 48 | tr -d '\n' | tr '/+' '_-' | cut -c1-64)"
  if grep -q '^API_JWT_SECRET=' .env; then
    sed -i.bak "s|^API_JWT_SECRET=.*|API_JWT_SECRET=${JWT_SECRET_VALUE}|" .env && rm -f .env.bak
  else
    printf '\nAPI_JWT_SECRET=%s\n' "$JWT_SECRET_VALUE" >> .env
  fi
  ok "JWT_SECRET gerado no .env"
fi

# APP_KEY do Lumen (32 bytes base64)
if grep -q '^API_APP_KEY=base64:CHANGE_ME_AFTER_INIT' .env 2>/dev/null || \
   ! grep -q '^API_APP_KEY=' .env 2>/dev/null; then
  APP_KEY_VALUE="base64:$(openssl rand -base64 32 | tr -d '\n')"
  if grep -q '^API_APP_KEY=' .env; then
    sed -i.bak "s|^API_APP_KEY=.*|API_APP_KEY=${APP_KEY_VALUE}|" .env && rm -f .env.bak
  else
    printf 'API_APP_KEY=%s\n' "$APP_KEY_VALUE" >> .env
  fi
  ok "APP_KEY gerado no .env"
fi

# ------------------------------------------------------------------------------
# 3) APP — Vue 3 + Vite + TS
# ------------------------------------------------------------------------------
if [ ! -f "$APP_DIR/package.json" ]; then
  log "Criando projeto Vue 3 + Vite em $APP_DIR"
  docker run --rm \
    -v "$ROOT_DIR/$APP_DIR":/app \
    -w /app \
    node:20-alpine \
    sh -c "npm create vite@latest . -- --template vue-ts --yes"
  ok "Vue 3 + Vite criado"
else
  warn "$APP_DIR já possui package.json — pulando scaffold"
fi

log "Instalando dependências do APP (axios + pinia + vue-router)"
docker run --rm \
  -v "$ROOT_DIR/$APP_DIR":/app \
  -w /app \
  node:20-alpine \
  sh -c "npm install && npm install axios pinia vue-router@4"
ok "Dependências Node instaladas"

# Ajuste do vite.config para host 0.0.0.0 (necessário em container)
VITE_CFG="$APP_DIR/vite.config.ts"
if [ -f "$VITE_CFG" ] && ! grep -q "host: true" "$VITE_CFG"; then
  log "Configurando vite para escutar em 0.0.0.0"
  python3 - "$VITE_CFG" <<'PY' || sed -i.bak 's|plugins: \[vue()\],|plugins: [vue()],\n  server: { host: true, port: 5173, watch: { usePolling: true } },|' "$VITE_CFG"
import re, sys
p = sys.argv[1]
with open(p, 'r', encoding='utf-8') as f:
    s = f.read()
if 'server:' not in s:
    s = re.sub(r'(plugins:\s*\[vue\(\)\],)', r"\1\n  server: { host: true, port: 5173, watch: { usePolling: true } },", s, count=1)
    with open(p, 'w', encoding='utf-8') as f:
        f.write(s)
PY
  rm -f "$VITE_CFG.bak"
  ok "vite.config.ts ajustado"
fi

# ------------------------------------------------------------------------------
# Final
# ------------------------------------------------------------------------------
cat <<EOF

==============================================================================
 mydraw — setup inicial concluído
==============================================================================
 Próximos passos:

   1) Suba o ambiente:
        docker compose up -d --build

   2) Acesse:
        Frontend ........... http://localhost:${APP_PORT:-5173}
        API (Lumen) ........ http://localhost:${API_PORT:-8088}
        MinIO Console ...... http://localhost:${MINIO_CONSOLE_PORT:-9101}
        MySQL .............. localhost:${MYSQL_PORT:-3306}

   3) Veja os logs:
        docker compose logs -f --tail=200
==============================================================================
EOF
