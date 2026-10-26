# Gotha viewer – Makefile

-include .env

WEB_ROOT ?= /var/www/html/gotha-viewer
APP_DOMAIN ?= https://www.daelba.eu
APP_PATH   ?= /html/gotha-viewer
# Plná URL vstupní stránky pro smoke testy (na VM1: https://gotha-view.daelba.eu)
SMOKE_BASE ?= $(APP_DOMAIN)$(APP_PATH)/public

.PHONY: init up down restart logs deploy deploy-force smoke smoke-local clean info

## Docker: sdílená síť proxy-net (idempotentní) + start/stop stacku
init:
	@docker network inspect proxy-net >/dev/null 2>&1 || docker network create proxy-net

up: init
	docker compose up --build -d

down:
	docker compose down

restart:
	docker compose up --build -d

logs:
	docker compose logs -f

## Nasadit kód do /var/www/html/gotha-viewer (zachová cache)
deploy:
	@echo "→ Kopíruji kód do $(WEB_ROOT)…"
	@mkdir -p $(WEB_ROOT)
	rsync -av --delete \
		--exclude 'cache/' \
		--exclude '.git/' \
		--exclude 'docs/' \
		--exclude 'tests/' \
		--exclude 'todo/' \
		--exclude 'Makefile' \
		--exclude '.gitignore' \
		--exclude '.env' \
		--exclude '*.md' \
		. $(WEB_ROOT)/
	@mkdir -p $(WEB_ROOT)/cache
	@chmod 777 $(WEB_ROOT)/cache
	@echo "✔ Hotovo → $(APP_DOMAIN)$(APP_PATH)/public/"

## Nasadit včetně cache
deploy-force:
	@echo "→ Kopíruji kód včetně cache do $(WEB_ROOT)…"
	@mkdir -p $(WEB_ROOT)
	rsync -av --delete \
		--exclude '.git/' \
		--exclude 'docs/' \
		--exclude 'tests/' \
		--exclude 'todo/' \
		--exclude 'Makefile' \
		--exclude '.gitignore' \
		--exclude '.env' \
		--exclude '*.md' \
		. $(WEB_ROOT)/
	@mkdir -p $(WEB_ROOT)/cache
	@chmod 777 $(WEB_ROOT)/cache
	@echo "✔ Hotovo"

## Smoke testy na produkci (z .env)
smoke:
	@echo "→ Smoke testy na $(SMOKE_BASE)"
	@bash tests/smoke/smoke.sh "$(SMOKE_BASE)"

## Smoke testy lokálně (vestavěný PHP server)
smoke-local:
	@echo "→ Lokální smoke testy na http://127.0.0.1:8470"
	@php -S 127.0.0.1:8470 -t public 2>/dev/null & \
	sleep 2; \
	bash tests/smoke/smoke.sh "http://127.0.0.1:8470"; \
	pkill -f "php -S 127.0.0.1:8470" 2>/dev/null || true

## Vyčistit lokální cache
clean:
	rm -f cache/*.cache
	@echo "✔ Cache vyčištěna"

## Info
info:
	@echo "  WEB_ROOT   = $(WEB_ROOT)"
	@echo "  APP_DOMAIN = $(APP_DOMAIN)"
	@echo "  APP_PATH   = $(APP_PATH)"
	@echo ""
	@echo "  make deploy       – nasadit (zachovat cache)"
	@echo "  make deploy-force – nasadit včetně cache"
	@echo "  make smoke        – smoke testy na produkci"
	@echo "  make smoke-local  – smoke testy lokálně"
	@echo "  make clean        – smazat lokální cache"
