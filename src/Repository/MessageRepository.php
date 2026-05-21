<?php

declare(strict_types=1);

namespace App\Repository;

use App\Document\Chat;
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
    public function findByLogicalChatOrderedForContext(Chat $chat, int $maxMessages): array
    {
        /** @var list<Message> $stored */
        $stored = $this->findAllByLogicalChatOrdered($chat);
        if (count($stored) > $maxMessages) {
            return array_slice($stored, -$maxMessages);
        }

        return $stored;
    }

    /**
     * @return list<Message>
     */
    public function findAllByLogicalChatOrdered(Chat $chat): array
    {
        /** @var list<Message> $stored */
        $stored = $this->findBy(['chat' => $chat], ['createdAt' => 'ASC']);

        return $stored;
    }

    public function countByLogicalChat(Chat $chat): int
    {
        if ($chat->getId() === null) {
            return 0;
        }

        return (int) $this->createQueryBuilder()
            ->field('chat')->equals($chat->getId())
            ->count()
            ->getQuery()
            ->execute();
    }

    public function deleteAllForTelegramChat(int $telegramChatId): void
    {
        $messages = $this->findBy(['telegramChatId' => $telegramChatId]);
        if ($messages === []) {
            return;
        }

        $dm = $this->getDocumentManager();
        foreach ($messages as $message) {
            $dm->remove($message);
        }
        $dm->flush();
    }

    /**
     * @param array<string, mixed> $telegramMessage об'єкт message з Telegram API
     */
    public function saveInboundUserFromTelegramPayload(array $telegramMessage, ?Chat $logicalChat = null): Message
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

        $this->linkToLogicalChat($entity, $logicalChat);

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
    public function saveAgentOutboundFromTelegramSendResponse(
        array $sent,
        bool $isGroup,
        ?Message $replyToInbound,
        ?Chat $logicalChat = null,
    ): void {
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

        $this->linkToLogicalChat($entity, $logicalChat);
    }

    public function linkExistingMessageToChat(Message $message, Chat $logicalChat): void
    {
        $this->linkToLogicalChat($message, $logicalChat);
    }

    private function linkToLogicalChat(Message $entity, ?Chat $logicalChat): void
    {
        $dm = $this->getDocumentManager();
        $dm->persist($entity);

        if ($logicalChat === null) {
            $dm->flush();

            return;
        }

        if ($logicalChat->getId() !== null) {
            $logicalChat = $dm->getReference(Chat::class, $logicalChat->getId());
        } else {
            $dm->persist($logicalChat);
        }

        $entity->setChat($logicalChat);
        $dm->flush();
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
