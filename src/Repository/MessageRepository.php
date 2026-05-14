<?php

declare(strict_types=1);

namespace App\Repository;

use App\Document\Group;
use App\Document\Message;
use App\Document\User;
use App\Enum\MessageType;
use App\Service\Telegram\TelegramMessageHelper;
use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use Doctrine\Bundle\MongoDBBundle\Repository\ServiceDocumentRepository;


final class MessageRepository extends ServiceDocumentRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    public function findOneByTelegramMessageIds(int $telegramChatId, int $telegramMessageId): ?Message
    {
        return $this->findOneBy([
            'telegramChatId' => $telegramChatId,
            'telegramMessageId' => $telegramMessageId,
        ]);
    }

    /**
     * @return list<Message>
     */
    public function findByChatOrderedForContext(int $telegramChatId, int $maxMessages): array
    {
        /** @var list<Message> $stored */
        $stored = $this->findBy(['telegramChatId' => $telegramChatId], ['createdAt' => 'ASC']);
        if (count($stored) > $maxMessages) {
            return array_slice($stored, -$maxMessages);
        }

        return $stored;
    }

    /**
     * @param array<string, mixed> $telegramMessage об'єкт message з Telegram API
     */
    public function saveInboundUserFromTelegramPayload(array $telegramMessage): Message
    {
        if (!isset($telegramMessage['chat']['id'], $telegramMessage['message_id'])) {
            throw new \InvalidArgumentException('Telegram message: missing chat.id or message_id.');
        }

        $isGroup = TelegramMessageHelper::isGroup($telegramMessage);

        $telegramChatId = (int) $telegramMessage['chat']['id'];
        $telegramMessageId = (int) $telegramMessage['message_id'];
        $type = $isGroup ? MessageType::UserGroup : MessageType::UserPrivate;

        $entity = $this->findOneByTelegramMessageIds($telegramChatId, $telegramMessageId)
            ?? new Message($telegramChatId, $telegramMessageId, $type);
        $entity->setType($type);

        $body = TelegramMessageHelper::visibleTextBody($telegramMessage);
        $entity->setText($body !== '' ? $body : null);

        $entity->setReplyTo($this->resolveReplyToFromTelegramPayload($telegramChatId, $telegramMessage));

        $fromPayload = $telegramMessage['from'] ?? null;
        if (is_array($fromPayload) && isset($fromPayload['id'])) {
            $dm = $this->getDocumentManager();
            $author = $dm->getRepository(User::class)->findOneBy(['telegramUserId' => (int) $fromPayload['id']]);
            $entity->setAuthor($author);
        } else {
            $entity->setAuthor(null);
        }

        if ($isGroup) {
            $group = $this->getDocumentManager()->getRepository(Group::class)->findOneBy(['telegramChatId' => $telegramChatId]);
            $entity->setGroup($group);
        } else {
            $entity->setGroup(null);
        }

        $this->getDocumentManager()->persist($entity);
        $this->getDocumentManager()->flush();

        return $entity;
    }

    public function saveInboundTextAfterTranscription(int $telegramChatId, int $telegramMessageId, string $text): void
    {
        $msg = $this->findOneByTelegramMessageIds($telegramChatId, $telegramMessageId);
        if ($msg === null) {
            return;
        }
        $msg->setText($text);
        $this->getDocumentManager()->flush();
    }

    /**
     * @param array<string, mixed> $sent об'єкт повідомлення з відповіді sendMessage
     */
    public function saveAgentOutboundFromTelegramSendResponse(array $sent, bool $isGroup, ?Message $replyToInbound): void
    {
        if (!isset($sent['chat']['id'], $sent['message_id'])) {
            return;
        }

        $telegramChatId = (int) $sent['chat']['id'];
        $telegramMessageId = (int) $sent['message_id'];
        $type = $isGroup ? MessageType::AgentGroup : MessageType::AgentPrivate;

        if ($this->findOneByTelegramMessageIds($telegramChatId, $telegramMessageId) !== null) {
            return;
        }

        $entity = new Message($telegramChatId, $telegramMessageId, $type);
        $entity->setAuthor(null);
        $text = isset($sent['text']) ? trim((string) $sent['text']) : '';
        $entity->setText($text !== '' ? $text : null);
        $entity->setReplyTo($replyToInbound);

        if ($isGroup) {
            $group = $this->getDocumentManager()->getRepository(Group::class)->findOneBy(['telegramChatId' => $telegramChatId]);
            $entity->setGroup($group);
        } else {
            $entity->setGroup(null);
        }

        $this->getDocumentManager()->persist($entity);
        $this->getDocumentManager()->flush();
    }

    /**
     * @param array<string, mixed> $telegramMessage
     */
    private function resolveReplyToFromTelegramPayload(int $telegramChatId, array $telegramMessage): ?Message
    {
        $rt = $telegramMessage['reply_to_message'] ?? null;
        if (!is_array($rt) || !isset($rt['message_id'])) {
            return null;
        }

        return $this->findOneByTelegramMessageIds($telegramChatId, (int) $rt['message_id']);
    }
}
