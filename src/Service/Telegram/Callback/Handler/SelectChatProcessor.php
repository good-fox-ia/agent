<?php

declare(strict_types=1);

namespace App\Service\Telegram\Callback\Handler;

use App\Repository\ChatRepository;
use App\Repository\UserRepository;
use App\Service\Telegram\Callback\CallbackDTO;
use App\Service\Telegram\Chat\ChatListPresenter;
use App\Service\Telegram\Chat\UserChatSwitcher;
use App\Service\Telegram\TelegramService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;

/**
 * Обробляє натискання inline-кнопки вибору бесіди (callback_data: sc:{chatId}).
 */
final class SelectChatProcessor
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly ChatRepository $chats,
        private readonly UserChatSwitcher $chatSwitcher,
        private readonly TelegramService $telegram,
        private readonly DocumentManager $documentManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function handles(string $data): bool
    {
        return str_starts_with($data, ChatListPresenter::CALLBACK_PREFIX);
    }

    public function process(CallbackDTO $callback): void
    {
        if (!$this->handles($callback->data)) return;

        $selectedChatId = substr($callback->data, strlen(ChatListPresenter::CALLBACK_PREFIX));
        if ($selectedChatId === '') return;

        try {
            $user = $this->users->findOneByTelegramUserId($callback->fromId);
            if ($user === null) return;

            $selectedChat = $this->chats->findOneByIdForUser($selectedChatId, $user);
            if ($selectedChat === null) {
                $this->telegram->answerCallbackQuery($callback->callbackId, 'Бесіду не знайдено');
                return;
            }

            $user->setCurrentChat($selectedChat);
            $this->telegram->answerCallbackQuery($callback->callbackId, 'Ви обрали бесіду');

            $isGroup = $callback->chatId < 0;
            $this->chatSwitcher->switchTo($user, $selectedChat, $callback->chatId, null, $isGroup);

        } catch (\Throwable $e) {
            $this->logger->error('Помилка callback вибору бесіди: {error}', ['error' => $e->getMessage()]);
            if ($callback->callbackId !== '') {
                try {
                    $this->telegram->answerCallbackQuery($callback->callbackId, 'Помилка перемикання');
                } catch (\Throwable) {
                    // ignore
                }
            }
        }
    }
}
