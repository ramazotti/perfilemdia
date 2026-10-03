#!/bin/bash
# Atualiza o Perfil em Dia na VPS a partir do main.
set -euo pipefail

DEPLOY="${DEPLOY_DIR:-/opt/docker-storage/perfilemdia.com.br/deploy}"
PROJECT="${COMPOSE_PROJECT_DIR:-/opt/docker/sites/perfilemdia.com.br}"

cd "$DEPLOY"
git fetch origin
git reset --hard origin/main
git clean -fd

docker compose \
  -f "$DEPLOY/deploy/vps/docker-compose.yml" \
  --project-directory "$PROJECT" \
  --profile ao-vivo \
  up -d --build --remove-orphans

echo "Deploy concluido"
