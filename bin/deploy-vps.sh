#!/bin/bash
# Atualiza o Perfil em Dia na VPS a partir do main.
set -euo pipefail

DEPLOY="${DEPLOY_DIR:-/opt/docker-storage/perfilemdia.com.br/deploy}"
PROJECT="${COMPOSE_PROJECT_DIR:-/opt/docker/sites/perfilemdia.com.br}"

cd "$DEPLOY"
git fetch origin
git reset --hard origin/main
git clean -fd

# O reset troca este arquivo no meio da execucao. Roda de novo a versao que acabou de chegar.
if [ "${DEPLOY_REEXEC:-}" != 1 ]; then
  export DEPLOY_REEXEC=1
  exec "$DEPLOY/bin/deploy-vps.sh"
fi

docker compose \
  -f "$DEPLOY/deploy/vps/docker-compose.yml" \
  --project-directory "$PROJECT" \
  --profile ao-vivo \
  up -d --build --remove-orphans

docker compose \
  -f "$DEPLOY/deploy/vps/docker-compose.yml" \
  --project-directory "$PROJECT" \
  exec -T php php bin/migrate.php

echo "Deploy concluido"
