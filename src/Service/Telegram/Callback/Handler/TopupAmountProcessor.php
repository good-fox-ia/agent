<?php

declare(strict_types=1);

namespace App\Service\Telegram\Callback\Handler;

use App\Repository\UserRepository;
use App\Service\Telegram\Api\TelegramService;
use App\Service\Telegram\Callback\CallbackDTO;
use App\Service\Telegram\Payment\StarsAmountValidator;
use App\Service\Telegram\Payment\StarsPaymentService;
use App\Service\Telegram\Payment\TopupResponder;
use Psr\Log\LoggerInterface;

/**
 * Обробляє натискання inline-кнопки поповнення (callback_data: tp:{amount}).
 */
final class TopupAmountProcessor
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly StarsPaymentService $starsPayment,
        private readonly TelegramService $telegram,
        private readonly LoggerInterface $logger,
    ) {}

    public function handles(string $data): bool
    {
        return str_starts_with($data, TopupResponder::CALLBACK_PREFIX);
    }

    public function process(CallbackDTO $callback): void
    {
        if (!$this->handles($callback->data)) {
            return;
        }

        $amountRaw = substr($callback->data, strlen(TopupResponder::CALLBACK_PREFIX));
        if (!preg_match('/^\d+$/', $amountRaw)) {
            $this->telegram->answerCallbackQuery($callback->callbackId, 'Невідома сума');

            return;
        }

        $amount = (int) $amountRaw;
        if (!StarsAmountValidator::isPresetAmount($amount)) {
            $this->telegram->answerCallbackQuery($callback->callbackId, 'Невідома сума');

            return;
        }

        try {
            if ($callback->messageId > 0) {
                $this->telegram->deleteMessage($callback->chatId, $callback->messageId);
            }

            $user = $this->users->upsertFromTelegramFromPayload(['id' => $callback->fromId]);
            $error = $this->starsPayment->sendTopupInvoice($user, $callback->chatId, $amount);
            if ($error !== null) {
                $this->telegram->answerCallbackQuery($callback->callbackId, $error);

                return;
            }

            $this->telegram->answerCallbackQuery($callback->callbackId, 'Рахунок надіслано');
        } catch (\Throwable $e) {
            $this->logger->error('Помилка callback topup: {error}', ['error' => $e->getMessage()], $e);
            if ($callback->callbackId !== '') {
                try {
                    $this->telegram->answerCallbackQuery($callback->callbackId, 'Помилка. Спробуй пізніше.');
                } catch (\Throwable) {
                    // ignore
                }
            }
        }
    }
}
