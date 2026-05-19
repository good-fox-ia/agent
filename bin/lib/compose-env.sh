# Спільні функції для bin/start, bin/stop, bin/deploy, bin/deploy-stop.
# shellcheck shell=bash

cd_to_project_root() {
  cd "$(dirname "${BASH_SOURCE[1]}")/.."
}

append_compose_env_files() {
  local -n target=$1
  [[ -f .env ]] && target+=(--env-file .env)
  [[ -f .env.local ]] && target+=(--env-file .env.local)
}

init_compose_dev() {
  COMPOSE=(docker compose)
  append_compose_env_files COMPOSE
}

init_compose_prod() {
  COMPOSE=(docker compose -f docker-compose.yml -f docker-compose.prod.yml)
  append_compose_env_files COMPOSE
}

wait_for_php_service() {
  local -n compose=$1
  echo "→ Очікування сервісу php..."
  for _ in $(seq 1 60); do
    if "${compose[@]}" exec -T php true 2>/dev/null; then
      return 0
    fi
    sleep 1
  done
  echo "Помилка: сервіс php не відповідає." >&2
  return 1
}

require_env_file() {
  local file=$1
  if [[ ! -f "$file" ]]; then
    echo "Помилка: потрібен файл $file (скопіюйте з .env.example)." >&2
    exit 1
  fi
}

require_prod_secrets() {
  # shellcheck disable=SC1091
  set -a
  [[ -f .env ]] && source .env
  [[ -f .env.local ]] && source .env.local
  set +a

  local missing=()
  [[ -z "${MONGODB_ROOT_USERNAME:-}" ]] && missing+=(MONGODB_ROOT_USERNAME)
  [[ -z "${MONGODB_ROOT_PASSWORD:-}" ]] && missing+=(MONGODB_ROOT_PASSWORD)
  [[ -z "${RABBITMQ_USER:-}" ]] && missing+=(RABBITMQ_USER)
  [[ -z "${RABBITMQ_PASSWORD:-}" ]] && missing+=(RABBITMQ_PASSWORD)

  if ((${#missing[@]} > 0)); then
    echo "Помилка: у .env.local задайте: ${missing[*]}" >&2
    exit 1
  fi
}
