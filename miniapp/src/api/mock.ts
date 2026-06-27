// In-memory mock-бекенд для розробки UI без Symfony.
// Активується, коли застосунок відкрито поза Telegram або VITE_USE_MOCK=true.

import type {
  AuthResponse,
  ChatSummary,
  InvoiceResponse,
  Me,
  SettingsPayload,
  TtsVoiceOption,
} from '@/types';

const me: Me = {
  user: {
    id: 'mock-user',
    telegramUserId: 123456789,
    firstName: 'Тест',
    lastName: 'Користувач',
    username: 'test_user',
    languageCode: 'uk',
    isPremium: true,
    voiceReply: false,
    ttsVoice: 'Kore',
    currentChatId: 'chat-1',
  },
  balance: { amount: 250 },
};

let systemPrompt = 'Ти корисний асистент. Відповідай українською, стисло та по суті.';

const chats: ChatSummary[] = [
  { id: 'chat-1', title: 'Робочий чат', isActive: true, messageCount: 142, updatedAt: '2026-06-15T14:10:00Z' },
  { id: 'chat-2', title: 'Ідеї', isActive: false, messageCount: 37, updatedAt: '2026-06-12T09:30:00Z' },
  { id: 'chat-3', title: 'Чернетки', isActive: false, messageCount: 8, updatedAt: '2026-06-01T18:05:00Z' },
];

// Підмножина голосів TtsVoice (бекенд віддаватиме повний список).
const voices: TtsVoiceOption[] = [
  { value: 'Zephyr', description: 'яскравий' },
  { value: 'Puck', description: 'бадьорий' },
  { value: 'Charon', description: 'інформативний' },
  { value: 'Kore', description: 'твердий' },
  { value: 'Fenrir', description: 'запальний' },
  { value: 'Leda', description: 'юний' },
  { value: 'Aoede', description: 'легкий' },
  { value: 'Callirrhoe', description: 'спокійний' },
  { value: 'Despina', description: "м'який плавний" },
  { value: 'Sulafat', description: 'теплий' },
];

const delay = <T>(value: T, ms = 350): Promise<T> =>
  new Promise((resolve) => setTimeout(() => resolve(value), ms));

const clone = <T>(value: T): T => JSON.parse(JSON.stringify(value)) as T;

export const mockApi = {
  auth(): Promise<AuthResponse> {
    return delay({ token: 'mock-token', me: clone(me) });
  },

  me(): Promise<Me> {
    return delay(clone(me));
  },

  chats(): Promise<ChatSummary[]> {
    return delay(clone(chats));
  },

  activateChat(id: string): Promise<ChatSummary[]> {
    for (const c of chats) c.isActive = c.id === id;
    me.user.currentChatId = id;
    return delay(clone(chats));
  },

  systemPrompt(): Promise<string> {
    return delay(systemPrompt);
  },

  voices(): Promise<TtsVoiceOption[]> {
    return delay(clone(voices));
  },

  updateSettings(payload: SettingsPayload): Promise<Me> {
    if (payload.voiceReply !== undefined) me.user.voiceReply = payload.voiceReply;
    if (payload.ttsVoice !== undefined) me.user.ttsVoice = payload.ttsVoice;
    if (payload.systemPrompt !== undefined) systemPrompt = payload.systemPrompt;
    return delay(clone(me));
  },

  createInvoice(stars: number): Promise<InvoiceResponse> {
    return delay({ invoiceLink: `https://t.me/invoice/mock-${stars}` });
  },
};
