<?php

declare(strict_types=1);

namespace App\Service\Telegram\Payment;

use App\Document\Payment;
use App\Document\User;
use App\Enum\PaymentStatus;
use App\Repository\BalanceRepository;
use App\Repository\PaymentRepository;
use App\Repository\UserRepository;
use App\Service\Telegram\Api\TelegramService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;

final class StarsPaymentService
{
    public const INVOICE_PAYLOAD_PREFIX = 'topup:';
    private const CURRENCY = 'XTR';

    public function __construct(
        private readonly BalanceRepository $balances,
        private readonly PaymentRepository $payments,
        private readonly UserRepository $users,
        private readonly TelegramService $telegram,
        private readonly DocumentManager $documentManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function getBalanceAmount(User $user): int
    {
        $balance = $this->balances->findOneByUser($user);

        return $balance?->getAmount() ?? 0;
    }

    public function formatBalanceText(User $user): string
    {
        $amount = $this->getBalanceAmount($user);
        $label = $this->formatUserLabel($user);

        return sprintf('Баланс %s: <b>%d ⭐</b>', $label, $amount);
    }

    public function sendTopupInvoice(User $payer, int $chatId, int $amount): ?string
    {
        if (!$this->telegram->isConfigured()) {
            return 'Telegram не налаштований.';
        }

        $validationError = StarsAmountValidator::validate($amount);
        if ($validationError !== null) {
            return $validationError;
        }

        try {
            $balance = $this->balances->getOrCreateForUser($payer);
            $payment = new Payment($balance, $payer, $amount);
            $balance->addPayment($payment);

            $this->documentManager->persist($payment);
            $this->documentManager->flush();

            $payload = self::INVOICE_PAYLOAD_PREFIX . $payment->getId();
            $payment->setInvoicePayload($payload);
            $this->documentManager->flush();

            $this->telegram->sendStarsInvoice(
                $chatId,
                $amount,
                $payload,
                sprintf('Поповнення балансу на %d ⭐', $amount),
            );

            return null;
        } catch (\Throwable $e) {
            $this->logger->error('Помилка створення інвойсу topup user={user} amount={amount}: {error}', [
                'user' => $payer->getTelegramUserId(),
                'amount' => $amount,
                'error' => $e->getMessage(),
            ], $e);

            return 'Не вдалося створити рахунок на оплату. Спробуй пізніше.';
        }
    }

    public function handlePreCheckout(array $preCheckoutQuery): void
    {
        $queryId = (string) ($preCheckoutQuery['id'] ?? '');
        if ($queryId === '') {
            return;
        }

        $invoicePayload = (string) ($preCheckoutQuery['invoice_payload'] ?? '');
        $currency = (string) ($preCheckoutQuery['currency'] ?? '');
        $totalAmount = (int) ($preCheckoutQuery['total_amount'] ?? 0);

        $payment = $this->payments->findOneByInvoicePayload($invoicePayload);
        if ($payment === null) {
            $this->telegram->answerPreCheckoutQuery($queryId, false, 'Платіж не знайдено.');

            return;
        }

        if ($payment->getStatus() !== PaymentStatus::PENDING) {
            $this->telegram->answerPreCheckoutQuery($queryId, false, 'Цей рахунок вже оброблено.');

            return;
        }

        if ($currency !== self::CURRENCY || $totalAmount !== $payment->getAmount()) {
            $payment->setStatus(PaymentStatus::FAILED);
            $this->documentManager->flush();
            $this->telegram->answerPreCheckoutQuery($queryId, false, 'Невірна сума платежу.');

            return;
        }

        $this->telegram->answerPreCheckoutQuery($queryId, true);
    }

    public function handleSuccessfulPayment(array $telegramMessage): void
    {
        $successfulPayment = $telegramMessage['successful_payment'] ?? null;
        if (!is_array($successfulPayment)) {
            return;
        }

        $from = $telegramMessage['from'] ?? null;
        if (!is_array($from) || !isset($from['id'])) {
            return;
        }

        $chargeId = (string) ($successfulPayment['telegram_payment_charge_id'] ?? '');
        $invoicePayload = (string) ($successfulPayment['invoice_payload'] ?? '');
        $currency = (string) ($successfulPayment['currency'] ?? '');
        $totalAmount = (int) ($successfulPayment['total_amount'] ?? 0);

        if ($chargeId === '' || $invoicePayload === '') {
            $this->logger->warning('successful_payment без charge_id або payload');

            return;
        }

        $existing = $this->payments->findOneByTelegramPaymentChargeId($chargeId);
        if ($existing !== null) {
            return;
        }

        $payment = $this->payments->findOneByInvoicePayload($invoicePayload);
        if ($payment === null) {
            $this->logger->warning('successful_payment: платіж не знайдено payload={payload}', [
                'payload' => $invoicePayload,
            ]);

            return;
        }

        if ($payment->getStatus() === PaymentStatus::COMPLETED) {
            return;
        }

        if ($currency !== self::CURRENCY || $totalAmount !== $payment->getAmount()) {
            $payment->setStatus(PaymentStatus::FAILED);
            $this->documentManager->flush();
            $this->logger->warning('successful_payment: невірна сума або валюта payment={payment}', [
                'payment' => $payment->getId(),
            ]);

            return;
        }

        $payer = $this->users->upsertFromTelegramFromPayload($from);
        if ($payer->getId() !== $payment->getPayer()->getId()) {
            $this->logger->warning('successful_payment: платник не збігається payment={payment}', [
                'payment' => $payment->getId(),
            ]);

            return;
        }

        try {
            $balance = $payment->getBalance();
            $balance->credit($payment->getAmount());
            $payment->markCompleted($chargeId);
            $this->documentManager->flush();

            $chatId = (int) ($telegramMessage['chat']['id'] ?? $payer->getTelegramUserId());
            $this->telegram->sendMessage(
                $chatId,
                sprintf(
                    "✅ Зараховано <b>%d ⭐</b>\n%s",
                    $payment->getAmount(),
                    $this->formatBalanceText($payer),
                ),
                ['parse_mode' => 'HTML'],
            );
        } catch (\Throwable $e) {
            $this->logger->error('Помилка зарахування Stars payment={payment}: {error}', [
                'payment' => $payment->getId(),
                'error' => $e->getMessage(),
            ], $e);
        }
    }

    private function formatUserLabel(User $user): string
    {
        $username = $user->getUsername();
        if ($username !== null && $username !== '') {
            return '@' . htmlspecialchars($username);
        }

        $firstName = $user->getFirstName();
        if ($firstName !== null && $firstName !== '') {
            return htmlspecialchars($firstName);
        }

        return 'користувача';
    }
}
