<?php

declare(strict_types=1);

namespace Windcave\Service;

class WindcaveResult
{
    public function __construct(
        private readonly bool $success,
        private readonly string $message,
        private readonly ?string $cardId = null
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
}
