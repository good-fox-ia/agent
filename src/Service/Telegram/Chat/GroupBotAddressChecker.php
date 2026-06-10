<?php

declare(strict_types=1);

namespace App\Service\Telegram\Chat;

use App\Enum\MessageType;
use App\Repository\MessageRepository;
use App\Service\Telegram\Api\TelegramMessageHelper;

/**
 * Чи звернулися до бота в групі: згадка @username або відповідь на повідомлення бота.
 */
final class GroupBotAddressChecker
{
    public const BOT_MENTION = '@vatra_group_bot';

    public function __construct(
        private readonly MessageRepository $messages,
    ) {}

    public function isAddressedToBot(int $telegramChatId, array $telegramMessage): bool
    {
        return $this->hasBotMention($telegramMessage)
            || $this->isReplyToBotMessage($telegramChatId, $telegramMessage);
    }

    public function hasBotMention(array $telegramMessage): bool
    {
        $text = TelegramMessageHelper::visibleTextBody($telegramMessage);

        return $text !== '' && stripos($text, self::BOT_MENTION) !== false;
    }

    /**
     * @param array<string, mixed> $telegramMessage
     */
    public function isReplyToBotMessage(int $telegramChatId, array $telegramMessage): bool
    {
        $replyTo = $telegramMessage['reply_to_message'] ?? null;
        if (!is_array($replyTo) || !isset($replyTo['message_id'])) {
            return false;
        }

        $repliedTo = $this->messages->findOneByTelegramMessageIds(
            $telegramChatId,
            (int) $replyTo['message_id'],
        );
        if ($repliedTo !== null) {
            return $repliedTo->getType() === MessageType::AgentGroup;
        }

        return is_array($replyTo['from'] ?? null) && ($replyTo['from']['is_bot'] ?? false) === true;
    }
}
