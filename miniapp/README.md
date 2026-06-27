# Agent — Telegram Mini App (фронтенд)

SPA для Telegram Mini App бота. Стек: **Vue 3 + TypeScript + Vite**, Pinia, Vue Router,
`@telegram-apps/sdk-vue`.

Працює у двох режимах:

- **У Telegram** — бере `initData`, автентифікується на бекенді (`POST /api/auth`).
- **У звичайному браузері (dev)** — `initData` немає → вмикається **mock-режим** із
  тестовими даними (`src/api/mock.ts`). UI можна розробляти без Symfony-бекенду.

## Передумови

Потрібен Node.js з npm (у системі зараз його немає — встанови, напр. через `brew install node`
або `nvm`). Перевір: `node -v && npm -v`.

## Запуск

```bash
cd miniapp
npm install
npm run dev          # http://localhost:5173 — відкриється в mock-режимі
```

Перевірка типів і прод-білд:

```bash
npm run typecheck
npm run build        # збирає у ../public/app (віддається тим самим вебсервером, що й API)
```

## Структура

```
src/
  main.ts            точка входу
  App.vue            бутстрап сесії, екран завантаження/помилки
  telegram.ts        обгортка SDK: init, тема→CSS-змінні, rawInitData, back-button, openInvoice
  types.ts           типи відповідей API
  router/            hash-роутер + керування системною кнопкою «Назад»
  stores/session.ts  Pinia: auth, профіль, баланс, налаштування
  api/
    client.ts        fetch-клієнт із Bearer-токеном (+ перемикач mock)
    mock.ts          in-memory бекенд для розробки без Symfony
  views/
    ProfileView.vue  профіль + баланс + навігація
    TopupView.vue    поповнення Stars (openInvoice)
    ChatsView.vue    список чатів + перемикання активного
    SettingsView.vue голос/TTS/системний промпт
  styles/main.css    стилі під тему Telegram (--tg-theme-*) з фолбеками
```

## Конфігурація (`.env`)

Скопіюй `.env.example` → `.env` і за потреби зміни:

- `VITE_API_BASE_URL` — база API. Порожньо = `/api` на тому ж хості (рекомендовано в проді).
- `VITE_USE_MOCK` — `true`, щоб примусово використовувати mock навіть у Telegram.
- `VITE_DEV_API_TARGET` — куди dev-сервер проксує `/api` (локальний Symfony).

## Очікувані ендпоінти бекенду

Фронт розрахований на такі контролери Symfony (їх ще треба зробити):

| Метод | Шлях | Призначення |
| --- | --- | --- |
| POST | `/api/auth` | валідація `initData` → `{ token, me }` |
| GET | `/api/me` | профіль + баланс |
| GET | `/api/chats` | список чатів |
| POST | `/api/chats/{id}/activate` | зробити чат активним |
| GET | `/api/settings/system-prompt` | поточний системний промпт |
| GET | `/api/settings/voices` | доступні голоси TTS |
| PUT | `/api/settings` | оновити voiceReply/ttsVoice/systemPrompt |
| POST | `/api/payments/invoice` | створити інвойс Stars → `{ invoiceLink }` |

Формати — у `src/types.ts`.

> Нагадування: Telegram Mini App вимагає **HTTPS** із валідним сертифікатом. Для локальної
> розробки під самим Telegram використовуй тунель (`cloudflared`/`ngrok`), що проксує на
> dev-сервер.
