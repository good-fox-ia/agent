# Agent (Symfony)

Проєкт розрахований на роботу в **Docker**: PHP-бот (long polling + Messenger), MongoDB, Redis і RabbitMQ. **Зовнішній HTTP для додатку не відкритий** — оновлення йдуть із Telegram API, відповіді надсилаються назовні з контейнера.

## Вимоги

- [Docker](https://docs.docker.com/get-docker/) і Docker Compose v2 (`docker compose`).

## Перший запуск

1. **Змінні середовища**  
   Скопіюйте та налаштуйте `.env` / `.env.local` (секрети краще тримати в `.env.local`, він не має потрапляти в git). Мінімум для Telegram-бота та LLM зазвичай потрібні `TELEGRAM_BOT_TOKEN`, `GROQ_API_KEY`, а також `APP_SECRET` для Symfony.

2. **Запуск усього проєкту** (з кореня репозиторію):

   ```bash
   ./bin/start
   ```

   Скрипт виконує: `docker compose up -d --build`, `composer install`, перезапуск `telegram-poll` і `messenger-worker`.

   Зупинка:

   ```bash
   ./bin/stop
   ```

## Корисні адреси після `up`

| Сервіс    | Адреса |
|-----------|--------|
| MongoDB   | `localhost:27017` (з хоста; у контейнерах — `mongodb:27017`) |
| Redis     | `localhost:6379` |
| RabbitMQ  | AMQP лише всередині Docker (`rabbitmq:5672`); веб-UI: http://localhost:15672 |

У сервісах PHP у `docker-compose.yml` уже задані `MONGODB_URI`, `REDIS_URL` і `MESSENGER_TRANSPORT_DSN` для імен хостів усередині мережі Docker.

## Сервіси бота

| Сервіс | Призначення |
|--------|-------------|
| `telegram-poll` | Long polling `getUpdates` → черга `telegram_inbound` |
| `messenger-worker` | Обробка всіх Telegram-черг Messenger |
| `php` | Тільки для `composer` і `bin/console` (`sleep infinity`) |

Логи:

```bash
docker compose logs -f telegram-poll
docker compose logs -f messenger-worker
```

## Деплой на сервер (Ubuntu / production)

1. Скопіюйте змінні: `cp .env.example .env.local` і заповніть секрети (`APP_SECRET`, `TELEGRAM_BOT_TOKEN`, `GROQ_API_KEY`, `MONGODB_ROOT_*`, `RABBITMQ_*`).
2. Запуск / оновлення (git pull, build, composer prod, cache, воркери):

   ```bash
   ./bin/deploy
   ```

3. Зупинка:

   ```bash
   ./bin/deploy-stop
   ```

Порти **27017** (MongoDB) і **15672** (RabbitMQ UI) відкриті ззовні; AMQP **5672** — лише в мережі Docker. Паролі беруться з `.env.local` (файл не комітиться).

> **Увага:** якщо MongoDB уже працювала без auth, увімкнення `MONGO_INITDB_ROOT_*` на існуючому volume не ввімкне auth автоматично — потрібен новий volume або ручне налаштування користувача.

## Щоденні команди (локально, dev)

| Дія | Команда |
|-----|---------|
| Запуск усього | `./bin/start` |
| Зупинка | `./bin/stop` |
| Деплой / оновлення на сервері | `./bin/deploy` |
| Зупинка production-стеку | `./bin/deploy-stop` |
| Symfony Console (dev) | `docker compose exec php php bin/console` |
| Symfony Console (prod) | `docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env --env-file .env.local exec php php bin/console` |
| Статистика черг | `docker compose exec php php bin/console messenger:stats` |

Приклад:

```bash
docker compose exec php php bin/console cache:clear
```

Ручний запуск polling або воркера (якщо зупинили відповідний сервіс):

```bash
docker compose exec php php bin/console app:telegram:event-updates
docker compose exec php php bin/console messenger:consume telegram_inbound telegram_messages telegram_audio telegram_message_private telegram_message_group -vv
```

## Оновлення після змін у `composer.json`

```bash
docker compose exec php composer update
```

---

Якщо образ PHP змінився (`docker/php/Dockerfile`), перезберіть контейнери: `docker compose up -d --build`.
