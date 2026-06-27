// Тонка обгортка над @telegram-apps/sdk.
// Усе захищено try/catch, щоб застосунок працював і поза Telegram (у звичайному
// браузері під час розробки) — тоді rawInitData === null і вмикається mock-режим.

import {
  init,
  isTMA,
  retrieveRawInitData,
  miniApp,
  themeParams,
  viewport,
  backButton,
  invoice,
  hapticFeedback,
} from '@telegram-apps/sdk-vue';

export interface TelegramBootstrap {
  /** Чи запущено всередині Telegram WebView. */
  inTelegram: boolean;
  /** Сирий initData-рядок для автентифікації на бекенді. */
  rawInitData: string | null;
}

let booted = false;

/** Ініціалізує SDK, прив'язує тему до CSS-змінних і повертає rawInitData. */
export function bootstrapTelegram(): TelegramBootstrap {
  let inside = false;
  try {
    inside = isTMA('simple');
  } catch {
    inside = false;
  }

  if (!inside) {
    return { inTelegram: false, rawInitData: null };
  }

  if (!booted) {
    try {
      init();
      booted = true;
    } catch (e) {
      console.warn('[tg] init failed', e);
      return { inTelegram: false, rawInitData: null };
    }

    safeMount(() => {
      themeParams.mountSync();
      themeParams.bindCssVars();
    });
    safeMount(() => {
      miniApp.mountSync();
      miniApp.bindCssVars();
    });
    safeMount(() => {
      viewport.mount();
      viewport.bindCssVars();
    });
    safe(() => miniApp.ready());
  }

  let raw: string | null = null;
  try {
    raw = retrieveRawInitData() ?? null;
  } catch (e) {
    console.warn('[tg] retrieveRawInitData failed', e);
  }

  return { inTelegram: true, rawInitData: raw };
}

let backClickBound = false;

/** Реєструє єдиний обробник кліку по системній кнопці «Назад». */
export function onBackButton(handler: () => void): void {
  if (backClickBound) return;
  safe(() => {
    if (backButton.mount.isAvailable()) backButton.mount();
    if (backButton.onClick.isAvailable()) {
      backButton.onClick(handler);
      backClickBound = true;
    }
  });
}

/** Показує/ховає системну кнопку «Назад». */
export function setBackButton(visible: boolean): void {
  safe(() => {
    if (backButton.mount.isAvailable()) backButton.mount();
    if (visible) {
      if (backButton.show.isAvailable()) backButton.show();
    } else if (backButton.hide.isAvailable()) {
      backButton.hide();
    }
  });
}

/** Відкриває інвойс Telegram Stars. Повертає статус, якщо доступно. */
export async function openInvoice(url: string): Promise<string | null> {
  try {
    if (invoice.open.isAvailable()) {
      return await invoice.open(url, 'url');
    }
  } catch (e) {
    console.warn('[tg] openInvoice failed', e);
  }
  return null;
}

/** Легка тактильна віддача (no-op поза Telegram). */
export function haptic(type: 'light' | 'medium' | 'heavy' = 'light'): void {
  safe(() => {
    if (hapticFeedback.impactOccurred.isAvailable()) {
      hapticFeedback.impactOccurred(type);
    }
  });
}

function safeMount(fn: () => void): void {
  try {
    fn();
  } catch (e) {
    console.warn('[tg] mount step failed', e);
  }
}

function safe(fn: () => void): void {
  try {
    fn();
  } catch {
    /* поза Telegram — ігноруємо */
  }
}
