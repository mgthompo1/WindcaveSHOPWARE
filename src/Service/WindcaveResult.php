<?php

declare(strict_types=1);

namespace Windcave\Service;

class WindcaveResult
{
    public function __construct(
        private readonly bool $success,
        private readonly string $message,
        private readonly ?string $cardId = null,
        private readonly ?string $transactionId = null,
        private readonly ?string $amount = null,
        private readonly ?string $currency = null,
        private readonly ?string $cardType = null,
        private readonly ?string $cardLast4 = null,
        private readonly ?string $cardExpiry = null
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->success;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getCardId(): ?string
    {
        return $this->cardId;
    }

    public function getTransactionId(): ?string
    {
        return $this->transactionId;
    }

    public function getAmount(): ?string
    {
        return $this->amount;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function getCardType(): ?string
    {
        return $this->cardType;
    }

    public function getCardLast4(): ?string
    {
        return $this->cardLast4;
    }

    public function getCardExpiry(): ?string
    {
        return $this->cardExpiry;
    }
}
