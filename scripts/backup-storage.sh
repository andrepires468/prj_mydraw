#!/usr/bin/env bash
# ==============================================================================
# mydraw — backup local: bucket MinIO (ZIP) + MySQL (ZIP) em backup/
#
# Pré-requisitos:
#   - Docker Compose: MinIO (profile local-storage) e MySQL (profile local-db) em execução
#   - .env na raiz: MINIO_*, DB_DATABASE, DB_USERNAME, DB_PASSWORD
#   - Comando `zip` no PATH, ou Docker usa Alpine 3.20 para gerar os ZIPs
#
# Uso: na raiz do repositório — bash scripts/backup-storage.sh
# ==============================================================================

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

# Git Bash no Windows: evita converter `/bin/sh` em caminho Win ao chamar Docker.
export MSYS_NO_PATHCONV=1

log() { printf "\033[1;36m▶ %s\033[0m\n" "$*"; }
ok()  { printf "\033[1;32m✔ %s\033[0m\n" "$*"; }
die() { printf "\033[1;31m✖ %s\033[0m\n" "$*" >&2; exit 1; }

if [[ -f .env ]]; then
  set -a
  # shellcheck disable=SC1091
  source .env
  set +a
fi

: "${MINIO_ROOT_USER:?Defina MINIO_ROOT_USER no .env}"
: "${MINIO_ROOT_PASSWORD:?Defina MINIO_ROOT_PASSWORD no .env}"
: "${MINIO_BUCKET:?Defina MINIO_BUCKET no .env}"
: "${DB_DATABASE:?Defina DB_DATABASE no .env}"
: "${DB_USERNAME:?Defina DB_USERNAME no .env}"
: "${DB_PASSWORD:?Defina DB_PASSWORD no .env}"

if ! command -v docker >/dev/null 2>&1; then
  die "Docker não encontrado no PATH."
fi

TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP_DIR="${ROOT_DIR}/backup"
STORAGE_STAGING="${BACKUP_DIR}/.staging-storage-$$"
DB_STAGING="${BACKUP_DIR}/.staging-db-$$"
STORAGE_ZIP="storage-backup-${TIMESTAMP}.zip"
DB_ZIP="database-backup-${TIMESTAMP}.zip"

cleanup() {
  rm -rf "${STORAGE_STAGING}" "${DB_STAGING}"
}
trap cleanup EXIT

mkdir -p "${BACKUP_DIR}" "${STORAGE_STAGING}" "${DB_STAGING}"

COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-mydraw}"
DOCKER_NETWORK="${COMPOSE_PROJECT_NAME}_mydraw"

zip_folder() {
  local staging_dir="$1"
  local zip_basename="$2"
  local zip_full="${BACKUP_DIR}/${zip_basename}"
  log "Compactando ${zip_basename}..."
  if command -v zip >/dev/null 2>&1; then
    (cd "${staging_dir}" && zip -q -r "${zip_full}" .)
  else
    log "Comando 'zip' ausente; usando imagem Alpine para gerar o ZIP..."
    docker run --rm \
      -v "${staging_dir}:/data:ro" \
      -v "${BACKUP_DIR}:/out" \
      alpine:3.20 \
      sh -c "apk add --no-cache zip >/dev/null && cd /data && zip -q -r '/out/${zip_basename}' ."
  fi
  ok "Arquivo salvo em ${zip_full}"
}

# ------------------------------------------------------------------------------
# MinIO
# ------------------------------------------------------------------------------
log "Sincronizando bucket \"${MINIO_BUCKET}\" (MinIO via Docker) → staging..."
docker run --rm \
  --network "${DOCKER_NETWORK}" \
  -v "${STORAGE_STAGING}:/out" \
  --entrypoint /bin/sh \
  "minio/mc:latest" \
  -c "
    set -e
    mc alias set local http://minio:9000 '${MINIO_ROOT_USER}' '${MINIO_ROOT_PASSWORD}'
    mc mirror --overwrite --quiet 'local/${MINIO_BUCKET}' /out/
  "

zip_folder "${STORAGE_STAGING}" "${STORAGE_ZIP}"

# ------------------------------------------------------------------------------
# MySQL
# ------------------------------------------------------------------------------
if [[ -z "$(docker compose ps -q --status running mysql 2>/dev/null || true)" ]]; then
  die "MySQL não está em execução. Inclua o profile local-db em COMPOSE_PROFILES e rode: docker compose up -d"
fi

log "Exportando banco \"${DB_DATABASE}\" (mysqldump)..."
SQL_FILE="${DB_DATABASE}.sql"
docker compose exec -T mysql mysqldump \
  -h 127.0.0.1 \
  -u "${DB_USERNAME}" \
  -p"${DB_PASSWORD}" \
  --single-transaction \
  --quick \
  --routines \
  --no-tablespaces \
  --default-character-set=utf8mb4 \
  "${DB_DATABASE}" > "${DB_STAGING}/${SQL_FILE}"

zip_folder "${DB_STAGING}" "${DB_ZIP}"

ok "Backups concluídos: ${STORAGE_ZIP} e ${DB_ZIP} em ${BACKUP_DIR}/"
