# Agent (Symfony)

Проєкт розрахований на роботу в **Docker**: PHP-FPM, Nginx, MongoDB, Redis і RabbitMQ піднімаються через `docker compose`.

## Вимоги

- [Docker](https://docs.docker.com/get-docker/) і Docker Compose v2 (`docker compose`).

## Перший запуск

1. **Змінні середовища**  
   Скопіюйте та налаштуйте `.env` / `.env.local` (секрети краще тримати в `.env.local`, він не має потрапляти в git). Мінімум для Telegram-бота та LLM зазвичай потрібні `TELEGRAM_BOT_TOKEN`, `GROQ_API_KEY`, а також `APP_SECRET` для Symfony.

2. **Збірка і старт контейнерів** (з кореня репозиторію):

   ```bash
   docker compose up -d --build
   ```

3. **Залежності PHP** (один раз після клону або оновлення `composer.lock`):

   ```bash
   docker compose exec php composer install
   ```

4. **Додаток у браузері**  
   HTTP: [http://localhost:8080](http://localhost:8080)

## Корисні адреси після `up`

| Сервіс    | Адреса |
|-----------|--------|
| Додаток   | http://localhost:8080 |
| MongoDB   | `localhost:27017` (з хоста; у PHP-контейнері — `mongodb:27017`) |
| Redis     | `localhost:6379` |
| RabbitMQ  | AMQP `localhost:5672`; веб-UI керування: http://localhost:15672 (логін/пароль за замовчуванням: `guest` / `guest`) |

У сервісі `php` у `docker-compose.yml` уже задані `MONGODB_URI`, `REDIS_URL` і `MESSENGER_TRANSPORT_DSN` для імен хостів усередині мережі Docker — вони перекривають локальні значення з `.env` під час роботи в контейнері.

## Щоденні команди

| Дія | Команда |
|-----|---------|
| Запуск у фоні | `docker compose up -d` |
| Зупинка | `docker compose down` |
| Логи (наприклад, PHP) | `docker compose logs -f php` |
| Symfony Console | `docker compose exec php php bin/console` |

Приклад:

```bash
docker compose exec php php bin/console cache:clear
```

## Telegram і Messenger

Long polling оновлень з Telegram (окремий довгоживучий процес):

```bash
docker compose exec php php bin/console app:telegram:event-updates
```

Обробка черг Messenger (воркери; можна запустити кілька терміналів або один процес з кількома транспортами):

```bash
docker compose exec php php bin/console messenger:consume telegram_messages telegram_audio telegram_message_private telegram_message_group -vv
```

Перегляд черг і помилок:

```bash
docker compose exec php php bin/console messenger:stats
```

## Оновлення після змін у `composer.json`

```bash
docker compose exec php composer update
```

---

Якщо образ PHP змінився (`docker/php/Dockerfile`), перезберіть контейнери: `docker compose up -d --build`.
