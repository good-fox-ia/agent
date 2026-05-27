<?php

declare(strict_types=1);

namespace App\Service\Telegram\Agent;

use App\Document\Message;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\Telegram\Api\TelegramMessageHelper;
use App\Service\Telegram\Api\TelegramService;
use App\Service\Telegram\Keyboard\UserReplyMarkupResolver;
use App\Service\Telegram\Persistence\TelegramPersistenceService;
use Psr\Log\LoggerInterface;

/**
 * Надсилає привітальне повідомлення з описом можливостей бота.
 */
final class WelcomeMessage
{
    private const TELEGRAM_MAX_MESSAGE_LENGTH = 4096;

    public function __construct(
        private readonly TelegramService $telegram,
        private readonly TelegramPersistenceService $persistence,
        private readonly MessageRepository $messages,
        private readonly UserRepository $users,
        private readonly UserReplyMarkupResolver $replyMarkup,
        private readonly LoggerInterface $logger,
    ) {}

    public function send(array $telegramMessage, ?Message $inbound): void
    {
        $chatId = (int) ($telegramMessage['chat']['id'] ?? 0);
        if ($chatId === 0) {
            return;
        }

        if (!$this->telegram->isConfigured()) {
            $this->logger->error('Telegram не налаштований, welcome пропущено для chat {chat}', ['chat' => $chatId]);

            return;
        }

        $isGroup = TelegramMessageHelper::isGroup($telegramMessage);
        $triggerMessageId = (int) ($telegramMessage['message_id'] ?? 0);
        $replyToInbound = $inbound ?? ($triggerMessageId > 0
            ? $this->messages->findOneByTelegramMessageIds($chatId, $triggerMessageId)
            : null);

        try {
            $text = mb_substr(self::buildText(), 0, self::TELEGRAM_MAX_MESSAGE_LENGTH);
            $options = ['parse_mode' => 'HTML'];
            if (!$isGroup) {
                $from = $telegramMessage['from'] ?? null;
                if (is_array($from) && isset($from['id'])) {
                    $user = $this->users->upsertFromTelegramFromPayload($from);
                    $options = $this->replyMarkup->applyForUser($user, $options);
                }
            }
            $sent = $this->telegram->sendMessage($chatId, $text, $options);
            $this->persistence->recordAgentOutboundFromTelegramSend($sent, $isGroup, $replyToInbound);
            $this->logger->info('Welcome надіслано в chat {chat}', ['chat' => $chatId]);
        } catch (\Throwable $e) {
            $this->logger->error('Помилка welcome chat={chat}: {error}', [
                'chat' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private static function buildText(): string
    {
        return <<<'TEXT'
👋 <b>Привіт!</b> Я твій <i>асистент</i> у Telegram.

<b>Що вмію:</b>

🕐 🔵 <b>Час</b> — поточний час (<i>можна вказати <u>часовий пояс</u></i>)
🌦️ 🟢 <b>Погода</b> — погода за <u>містом</u>
📨 🟣 <b>Повідомлення</b> — надішлю повідомлення користувачу в Telegram

✍️ Пиши питання <b>текстом</b> українською — відповім по суті.
🎤 Або <b>надішли голосове</b> — розпізнаю мову і так відповім.

💡 Команда <code>/help</code> — короткі підказки.
🆕 <code>/newchat</code> — нова бесіда (очистити історію в Telegram).
📋 <code>/listchats</code> — список ваших бесід (обрати кнопкою).
⌨️ <code>/keyboardon</code> та <code>/keyboardoff</code> — увімкнути або вимкнути кнопки внизу екрана.
⚙️ <code>/edit_system_promt</code> — змінити system prompt для активної бесіди (<code>reset</code> — скинути).
TEXT;
    }
}

