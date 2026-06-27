// Типи відповідей API. Дзеркалять майбутні Symfony-контролери (/api/*).

export interface Profile {
  id: string;
  telegramUserId: number;
  firstName: string | null;
  lastName: string | null;
  username: string | null;
  languageCode: string | null;
  isPremium: boolean;
  voiceReply: boolean;
  ttsVoice: string | null;
  currentChatId: string | null;
}

export interface BalanceInfo {
  amount: number;
}

export interface Me {
  user: Profile;
  balance: BalanceInfo;
}

export interface ChatSummary {
  id: string;
  title: string;
  isActive: boolean;
  messageCount: number;
  updatedAt: string;
}

export interface SettingsPayload {
  voiceReply?: boolean;
  ttsVoice?: string | null;
  systemPrompt?: string;
}

export interface TtsVoiceOption {
  value: string;
  description: string;
}

export interface InvoiceResponse {
  /** Посилання на інвойс для Telegram.WebApp.openInvoice(). */
  invoiceLink: string;
}

export interface AuthResponse {
  token: string;
  me: Me;
}
