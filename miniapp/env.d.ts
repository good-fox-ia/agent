/// <reference types="vite/client" />

interface ImportMetaEnv {
  /** База API. Якщо порожньо — використовується '/api' (той самий хост). */
  readonly VITE_API_BASE_URL?: string;
  /** 'true' — примусово використовувати mock-дані навіть усередині Telegram. */
  readonly VITE_USE_MOCK?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}

declare module '*.vue' {
  import type { DefineComponent } from 'vue';
  const component: DefineComponent<Record<string, unknown>, Record<string, unknown>, unknown>;
  export default component;
}
