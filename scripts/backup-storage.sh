#!/usr/bin/env bash
# ==============================================================================
# mydraw — backup local: MinIO (ZIP) + MySQL (ZIP) + arquivos .env* (ZIP) em backup/
# Opcional: upload dos ZIPs para o MEGA (MEGAcmd); se todos os uploads deste run
# forem OK, apaga esses ZIPs no disco (ficam só no MEGA).
#
# Pré-requisitos:
#   - Docker Compose: MinIO (profile local-storage) e MySQL (profile local-db) em execução
#   - .env na raiz: MINIO_*, DB_DATABASE, DB_USERNAME, DB_PASSWORD
#   - Comando `zip` no PATH, ou Docker usa Alpine 3.20 para gerar os ZIPs
#   - MEGA: https://mega.nz/cmd — variáveis MEGA_* opcionais no .env
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
warn() { printf "\033[1;33m! %s\033[0m\n" "$*" >&2; }
die() { printf "\033[1;31m✖ %s\033[0m\n" "$*" >&2; exit 1; }

# Carrega um .env preservando caracteres especiais (!, #, espaços, etc.)
load_env_vars() {
  local env_file="$1"
  [[ -f "$env_file" ]] || return 0
  local line var val
  while IFS= read -r line || [[ -n "$line" ]]; do
    line="${line%$'\r'}"
    [[ "$line" =~ ^[[:space:]]*# ]] && continue
    [[ -z "${line//[[:space:]]/}" ]] && continue
    if [[ "$line" =~ ^(MINIO_ROOT_USER|MINIO_ROOT_PASSWORD|MINIO_BUCKET|DB_DATABASE|DB_USERNAME|DB_PASSWORD|COMPOSE_PROJECT_NAME|MEGA_EMAIL|MEGA_PASSWORD|MEGA_REMOTE_DIR|MEGA_KEEP_DAYS|KEEP_DAYS)= ]]; then
      var="${line%%=*}"
      val="${line#*=}"
      val="${val%$'\r'}"
      val="${val#"${val%%[![:space:]]*}"}"
      val="${val%"${val##*[![:space:]]}"}"
      if [[ "$val" =~ ^\".*\"$ ]]; then
        val="${val:1:${#val}-2}"
      elif [[ "$val" =~ ^\'.*\'$ ]]; then
        val="${val:1:${#val}-2}"
      fi
      export "$var=$val"
    fi
  done < "$env_file"
}

load_env_vars "${ROOT_DIR}/.env"

: "${MINIO_ROOT_USER:?Defina MINIO_ROOT_USER no .env}"
: "${MINIO_ROOT_PASSWORD:?Defina MINIO_ROOT_PASSWORD no .env}"
: "${MINIO_BUCKET:?Defina MINIO_BUCKET no .env}"
: "${DB_DATABASE:?Defina DB_DATABASE no .env}"
: "${DB_USERNAME:?Defina DB_USERNAME no .env}"
: "${DB_PASSWORD:?Defina DB_PASSWORD no .env}"

if ! command -v docker >/dev/null 2>&1; then
  die "Docker não encontrado no PATH."
fi

KEEP_DAYS="${KEEP_DAYS:-30}"
MEGA_REMOTE_DIR="${MEGA_REMOTE_DIR:-/Backups/mydraw}"
MEGA_KEEP_DAYS="${MEGA_KEEP_DAYS:-$KEEP_DAYS}"

TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP_DIR="${ROOT_DIR}/backup"
STORAGE_STAGING="${BACKUP_DIR}/.staging-storage-$$"
DB_STAGING="${BACKUP_DIR}/.staging-db-$$"
STORAGE_ZIP="storage-backup-${TIMESTAMP}.zip"
DB_ZIP="database-backup-${TIMESTAMP}.zip"
ENV_ZIP="env-backup-${TIMESTAMP}.zip"
STORAGE_ZIP_PATH="${BACKUP_DIR}/${STORAGE_ZIP}"
DB_ZIP_PATH="${BACKUP_DIR}/${DB_ZIP}"
ENV_STAGING="${BACKUP_DIR}/.staging-env-$$"
ENV_ZIP_PATH=""

cleanup() {
  rm -rf "${STORAGE_STAGING}" "${DB_STAGING}" "${ENV_STAGING}"
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

# ------------------------------------------------------------------------------
# Arquivos .env* (estrutura relativa à raiz do repositório)
# ------------------------------------------------------------------------------
log "Empacotando arquivos .env* do projeto..."
mapfile -t env_files < <(cd "$ROOT_DIR" && find . -name '.env*' -type f \
  -not -path './node_modules/*' -not -path '*/node_modules/*' \
  -not -path './vendor/*' -not -path '*/vendor/*' \
  -not -path './.git/*' \
  -not -path './backup/*' \
  | sed 's|^\./||' | sort)

if [[ "${#env_files[@]}" -gt 0 ]]; then
  mkdir -p "${ENV_STAGING}"
  for f in "${env_files[@]}"; do
    mkdir -p "${ENV_STAGING}/$(dirname "$f")"
    cp "${ROOT_DIR}/${f}" "${ENV_STAGING}/${f}"
  done
  zip_folder "${ENV_STAGING}" "${ENV_ZIP}"
  ENV_ZIP_PATH="${BACKUP_DIR}/${ENV_ZIP}"
  ok "Incluídos ${#env_files[@]} arquivo(s) .env* em ${ENV_ZIP}"
else
  warn "Nenhum arquivo .env* encontrado — pulando ZIP de configuração."
fi

if [[ -n "${ENV_ZIP_PATH}" ]]; then
  ok "Backups locais concluídos: ${STORAGE_ZIP}, ${DB_ZIP} e ${ENV_ZIP} em ${BACKUP_DIR}/"
else
  ok "Backups locais concluídos: ${STORAGE_ZIP} e ${DB_ZIP} em ${BACKUP_DIR}/"
fi

# ------------------------------------------------------------------------------
# Upload para o MEGA (requer MEGAcmd: https://mega.nz/cmd)
# ------------------------------------------------------------------------------
if [[ -n "${LOCALAPPDATA:-}" ]] && command -v cygpath >/dev/null 2>&1; then
  WIN_MEGACMD_DIR_MSYS="$(cygpath -u "${LOCALAPPDATA}\\MEGAcmd" 2>/dev/null || true)"
  if [[ -n "$WIN_MEGACMD_DIR_MSYS" && -d "$WIN_MEGACMD_DIR_MSYS" ]]; then
    case ":$PATH:" in
      *":$WIN_MEGACMD_DIR_MSYS:"*) ;;
      *) export PATH="$PATH:$WIN_MEGACMD_DIR_MSYS" ;;
    esac
  fi
fi

MEGA_SUFFIX=""
if command -v mega-put >/dev/null 2>&1; then
  MEGA_SUFFIX=""
elif command -v mega-put.bat >/dev/null 2>&1; then
  MEGA_SUFFIX=".bat"
  export MSYS2_ARG_CONV_EXCL="*"
fi

if command -v "mega-put${MEGA_SUFFIX}" >/dev/null 2>&1; then
  log "Enviando backups para o MEGA em '${MEGA_REMOTE_DIR}'..."

  if [[ -n "${MEGA_EMAIL:-}" && -n "${MEGA_PASSWORD:-}" ]]; then
    if ! "mega-whoami${MEGA_SUFFIX}" 2>/dev/null | grep -q "$MEGA_EMAIL"; then
      "mega-login${MEGA_SUFFIX}" "$MEGA_EMAIL" "$MEGA_PASSWORD" >/dev/null
    fi
  fi

  "mega-mkdir${MEGA_SUFFIX}" -p "$MEGA_REMOTE_DIR" >/dev/null 2>&1 || true

  upload_to_mega() {
    local local_file="$1"
    local upload_path="$local_file"
    if [[ "$MEGA_SUFFIX" == ".bat" ]] && command -v cygpath >/dev/null 2>&1; then
      upload_path="$(cygpath -w "$local_file")"
    fi
    if "mega-put${MEGA_SUFFIX}" -c "$upload_path" "$MEGA_REMOTE_DIR/"; then
      ok "Upload concluído: $MEGA_REMOTE_DIR/$(basename "$local_file")"
      return 0
    fi
    return 1
  }

  UPLOAD_OK=1
  upload_to_mega "$STORAGE_ZIP_PATH" || UPLOAD_OK=0
  upload_to_mega "$DB_ZIP_PATH" || UPLOAD_OK=0
  if [[ -n "${ENV_ZIP_PATH}" ]]; then
    upload_to_mega "$ENV_ZIP_PATH" || UPLOAD_OK=0
  fi

  if [[ "$UPLOAD_OK" -eq 1 ]]; then
    rm -f "${STORAGE_ZIP_PATH}" "${DB_ZIP_PATH}"
    [[ -n "${ENV_ZIP_PATH}" ]] && rm -f "${ENV_ZIP_PATH}"
    ok "ZIPs locais removidos após upload ao MEGA."

    if [[ "${MEGA_KEEP_DAYS:-0}" =~ ^[0-9]+$ ]] && [[ "$MEGA_KEEP_DAYS" -gt 0 ]]; then
      CUTOFF="$(date -d "$MEGA_KEEP_DAYS days ago" +%Y%m%d 2>/dev/null || date -v-"${MEGA_KEEP_DAYS}"d +%Y%m%d)"
      REMOVED_REMOTE=0
      while IFS= read -r remote_line; do
        [[ -z "$remote_line" ]] && continue
        base="$(basename "$remote_line")"
        [[ "$base" =~ ^(storage|database|env)-backup-.*\.zip$ ]] || continue
        file_date="$(echo "$base" | sed -n 's/.*backup-\([0-9]\{8\}\)-.*/\1/p')"
        [[ -n "$file_date" && "$file_date" =~ ^[0-9]{8}$ ]] || continue
        if [[ "$file_date" -lt "$CUTOFF" ]]; then
          if "mega-rm${MEGA_SUFFIX}" "$MEGA_REMOTE_DIR/$base" >/dev/null 2>&1; then
            REMOVED_REMOTE=$((REMOVED_REMOTE + 1))
          fi
        fi
      done < <("mega-ls${MEGA_SUFFIX}" "$MEGA_REMOTE_DIR" 2>/dev/null | grep -E '(storage|database|env)-backup-.*\.zip$' || true)

      if [[ "$REMOVED_REMOTE" -gt 0 ]]; then
        ok "Removidos ${REMOVED_REMOTE} arquivo(s) antigo(s) no MEGA."
      fi
    else
      log "Expiração no MEGA desabilitada (MEGA_KEEP_DAYS=${MEGA_KEEP_DAYS:-0})."
    fi
  else
    warn "Falha ao enviar um ou mais arquivos para o MEGA."
  fi
else
  warn "'mega-put' não encontrado — pulando upload para o MEGA (instale MEGAcmd)."
fi

# Remove ZIPs locais com mais de KEEP_DAYS dias
if [[ "${KEEP_DAYS:-0}" =~ ^[0-9]+$ ]] && [[ "$KEEP_DAYS" -gt 0 ]]; then
  REMOVED="$(find "$BACKUP_DIR" -type f \( -name 'storage-backup-*.zip' -o -name 'database-backup-*.zip' -o -name 'env-backup-*.zip' \) -mtime +"$KEEP_DAYS" -print -delete 2>/dev/null | wc -l)"
  REMOVED="${REMOVED//[[:space:]]/}"
  if [[ "${REMOVED:-0}" -gt 0 ]]; then
    ok "Removidos ${REMOVED} arquivo(s) local(is) com mais de ${KEEP_DAYS} dias."
  fi
fi

ok "Concluído."
