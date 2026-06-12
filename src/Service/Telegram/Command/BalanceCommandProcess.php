<?php

declare(strict_types=1);

namespace App\Service\Telegram\Command;

use App\Document\Message;
use App\Document\User;
use App\Enum\TelegramBotCommand;
use App\Repository\UserRepository;
use App\Service\Telegram\Api\TelegramMessageHelper;
use App\Service\Telegram\Payment\StarsPaymentService;
use App\Service\Telegram\Persistence\TelegramPersistenceService;
use App\Service\Telegram\UI\UserMessageSender;
use Psr\Log\LoggerInterface;

final class BalanceCommandProcess implements CommandProcessInterface
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly StarsPaymentService $starsPayment,
        private readonly UserMessageSender $messageSender,
        private readonly TelegramPersistenceService $persistence,
        private readonly LoggerInterface $logger,
    ) {}

    public function handles(TelegramBotCommand $command): bool
    {
        return $command === TelegramBotCommand::BALANCE;
    }

    public function onProcess(array $telegramMessage, ?Message $inbound): void
    {
        $chatId = (int) ($telegramMessage['chat']['id'] ?? 0);
        $from = $telegramMessage['from'] ?? null;

        if ($chatId === 0 || !is_array($from) || !isset($from['id'])) {
            return;
        }

        $isGroup = TelegramMessageHelper::isGroup($telegramMessage);
        $user = $this->users->upsertFromTelegramFromPayload($from);

        try {
            $text = $this->starsPayment->formatBalanceText($user);
            $this->sendReply($user, $chatId, $inbound, $isGroup, $text);
        } catch (\Throwable $e) {
            $this->logger->error('Помилка balance chat={chat}: {error}', [
                'chat' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sendReply(User $user, int $chatId, ?Message $inbound, bool $isGroup, string $text): void
    {
        try {
            $sent = $isGroup
                ? $this->messageSender->send($chatId, $text, true)
                : $this->messageSender->sendToUser($user, $text);

            $this->persistence->recordAgentOutboundFromTelegramSend(
                $sent,
                $isGroup,
                $inbound,
                $user->getCurrentChat(),
            );
        } catch (\Throwable $e) {
            $this->logger->error('Помилка відповіді balance chat={chat}: {error}', [
                'chat' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
