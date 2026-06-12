<?php

declare(strict_types=1);

namespace App\Service\Telegram\Command;

use App\Document\Message;
use App\Document\User;
use App\Enum\TelegramBotCommand;
use App\Repository\UserRepository;
use App\Service\Telegram\Api\TelegramMessageHelper;
use App\Service\Telegram\Payment\StarsPaymentService;
use App\Service\Telegram\Payment\TopupResponder;
use App\Service\Telegram\Persistence\TelegramPersistenceService;
use App\Service\Telegram\UI\UserMessageSender;
use Psr\Log\LoggerInterface;

final class TopupCommandProcess implements CommandProcessInterface
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly TopupResponder $topupResponder,
        private readonly StarsPaymentService $starsPayment,
        private readonly UserMessageSender $messageSender,
        private readonly TelegramPersistenceService $persistence,
        private readonly LoggerInterface $logger,
    ) {}

    public function handles(TelegramBotCommand $command): bool
    {
        return $command === TelegramBotCommand::TOPUP;
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
            $args = trim(TelegramMessageHelper::commandArguments($telegramMessage));
            if ($args === '') {
                $this->topupResponder->send($user, $chatId, $inbound, $isGroup);

                return;
            }

            if (!preg_match('/^\d+$/', $args)) {
                $this->sendReply(
                    $user,
                    $chatId,
                    $inbound,
                    $isGroup,
                    "Невірна сума.\nПриклад: <code>/topup 25</code>",
                );

                return;
            }

            $error = $this->starsPayment->sendTopupInvoice($user, $chatId, (int) $args);
            if ($error !== null) {
                $this->sendReply($user, $chatId, $inbound, $isGroup, $error);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Помилка topup chat={chat}: {error}', [
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
            $this->logger->error('Помилка відповіді topup chat={chat}: {error}', [
                'chat' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
