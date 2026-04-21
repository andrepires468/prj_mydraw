# ==============================================================================
# mydraw — atalhos de desenvolvimento
# ==============================================================================

DC      := docker compose
API     := mydraw_api
APP     := mydraw_app

.DEFAULT_GOAL := help

# ------------------------------------------------------------------------------
# Help
# ------------------------------------------------------------------------------
.PHONY: help
help: ## Lista comandos disponíveis
	@awk 'BEGIN {FS = ":.*##"; printf "\nComandos:\n"} /^[a-zA-Z_-]+:.*?##/ { printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2 }' $(MAKEFILE_LIST)

# ------------------------------------------------------------------------------
# Setup / Ciclo de vida
# ------------------------------------------------------------------------------
.PHONY: init
init: ## Bootstrap inicial (cria Lumen + Vue dentro de apps/)
	bash scripts/init.sh

.PHONY: up
up: ## Sobe todos os serviços (usa MinIO remoto definido no .env)
	$(DC) up -d --build

.PHONY: up-local
up-local: ## Sobe todos os serviços + MinIO local (profile local-storage)
	$(DC) --profile local-storage up -d --build

.PHONY: down
down: ## Derruba todos os serviços (incluindo MinIO local, se estiver ativo)
	$(DC) --profile local-storage down

.PHONY: restart
restart: ## Reinicia todos os serviços
	$(DC) restart

.PHONY: build
build: ## Rebuild das imagens
	$(DC) build --no-cache

.PHONY: ps
ps: ## Lista containers
	$(DC) ps

.PHONY: logs
logs: ## Segue os logs de todos os serviços
	$(DC) logs -f --tail=200

.PHONY: logs-api
logs-api: ## Logs só da API
	$(DC) logs -f --tail=200 api nginx

.PHONY: logs-app
logs-app: ## Logs só do APP
	$(DC) logs -f --tail=200 app

# ------------------------------------------------------------------------------
# Shells
# ------------------------------------------------------------------------------
.PHONY: api-sh
api-sh: ## Shell no container da API
	$(DC) exec api bash || $(DC) exec api sh

.PHONY: app-sh
app-sh: ## Shell no container do APP
	$(DC) exec app sh

# ------------------------------------------------------------------------------
# Comandos API (artisan / composer)
# ------------------------------------------------------------------------------
.PHONY: artisan
artisan: ## Roda artisan: make artisan ARGS="migrate"
	$(DC) exec api php artisan $(ARGS)

.PHONY: composer
composer: ## Roda composer: make composer ARGS="require pkg/name"
	$(DC) exec api composer $(ARGS)

.PHONY: migrate
migrate: ## Atalho para php artisan migrate
	$(DC) exec api php artisan migrate

# ------------------------------------------------------------------------------
# Comandos APP (npm)
# ------------------------------------------------------------------------------
.PHONY: npm
npm: ## Roda npm no APP: make npm ARGS="install axios"
	$(DC) exec app npm $(ARGS)

# ------------------------------------------------------------------------------
# Limpeza
# ------------------------------------------------------------------------------
.PHONY: clean
clean: ## Derruba e remove volumes (DESTRÓI dados locais, incl. MinIO local!)
	$(DC) --profile local-storage down -v --remove-orphans

.PHONY: prune
prune: ## Remove imagens/containers/volumes dangling do docker
	docker system prune -f
