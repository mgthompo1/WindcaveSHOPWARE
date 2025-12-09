<?php

declare(strict_types=1);

namespace Windcave\Core\Content\Transaction;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

/**
 * Windcave Transaction Entity
 *
 * Represents a single Windcave payment transaction record.
 */
class WindcaveTransactionEntity extends Entity
{
    use EntityIdTrait;

    protected ?string $orderTransactionId = null;
    protected ?string $sessionId = null;
    protected ?string $windcaveTransactionId = null;
    protected ?string $transactionType = null;
    protected ?string $status = null;
    protected ?string $responseCode = null;
    protected ?string $responseText = null;
    protected ?float $amount = null;
    protected ?string $currency = null;
    protected ?string $cardScheme = null;
    protected ?string $cardNumberMasked = null;
    protected ?string $cardExpiry = null;
    protected ?string $cardToken = null;
    protected ?string $threeDsStatus = null;
    protected ?string $rrn = null;
    protected ?string $authCode = null;
    protected ?string $paymentMode = null;
    protected ?string $testMode = null;
    protected ?string $rawResponse = null;
    protected ?OrderTransactionEntity $orderTransaction = null;

    public function getOrderTransactionId(): ?string
    {
        return $this->orderTransactionId;
    }

    public function setOrderTransactionId(?string $orderTransactionId): void
    {
        $this->orderTransactionId = $orderTransactionId;
    }

    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    public function setSessionId(?string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    public function getWindcaveTransactionId(): ?string
    {
        return $this->windcaveTransactionId;
    }

    public function setWindcaveTransactionId(?string $windcaveTransactionId): void
    {
        $this->windcaveTransactionId = $windcaveTransactionId;
    }

    public function getTransactionType(): ?string
    {
        return $this->transactionType;
    }

    public function setTransactionType(?string $transactionType): void
    {
        $this->transactionType = $transactionType;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): void
    {
        $this->status = $status;
    }

    public function getResponseCode(): ?string
    {
        return $this->responseCode;
    }

    public function setResponseCode(?string $responseCode): void
    {
        $this->responseCode = $responseCode;
    }

    public function getResponseText(): ?string
    {
        return $this->responseText;
    }

    public function setResponseText(?string $responseText): void
    {
        $this->responseText = $responseText;
    }

    public function getAmount(): ?float
    {
        return $this->amount;
    }

    public function setAmount(?float $amount): void
    {
        $this->amount = $amount;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(?string $currency): void
    {
        $this->currency = $currency;
    }

    public function getCardScheme(): ?string
    {
        return $this->cardScheme;
    }

    public function setCardScheme(?string $cardScheme): void
    {
        $this->cardScheme = $cardScheme;
    }

    public function getCardNumberMasked(): ?string
    {
        return $this->cardNumberMasked;
    }

    public function setCardNumberMasked(?string $cardNumberMasked): void
    {
        $this->cardNumberMasked = $cardNumberMasked;
    }

    public function getCardExpiry(): ?string
    {
        return $this->cardExpiry;
    }

    public function setCardExpiry(?string $cardExpiry): void
    {
        $this->cardExpiry = $cardExpiry;
    }

    public function getCardToken(): ?string
    {
        return $this->cardToken;
    }

    public function setCardToken(?string $cardToken): void
    {
        $this->cardToken = $cardToken;
    }

    public function getThreeDsStatus(): ?string
    {
        return $this->threeDsStatus;
    }

    public function setThreeDsStatus(?string $threeDsStatus): void
    {
        $this->threeDsStatus = $threeDsStatus;
    }

    public function getRrn(): ?string
    {
        return $this->rrn;
    }

    public function setRrn(?string $rrn): void
    {
        $this->rrn = $rrn;
    }

    public function getAuthCode(): ?string
    {
        return $this->authCode;
    }

    public function setAuthCode(?string $authCode): void
    {
        $this->authCode = $authCode;
    }

    public function getPaymentMode(): ?string
    {
        return $this->paymentMode;
    }

    public function setPaymentMode(?string $paymentMode): void
    {
        $this->paymentMode = $paymentMode;
    }

    public function getTestMode(): ?string
    {
        return $this->testMode;
    }

    public function setTestMode(?string $testMode): void
    {
        $this->testMode = $testMode;
    }

    public function getRawResponse(): ?string
    {
        return $this->rawResponse;
    }

    public function setRawResponse(?string $rawResponse): void
    {
        $this->rawResponse = $rawResponse;
    }

    public function getOrderTransaction(): ?OrderTransactionEntity
    {
        return $this->orderTransaction;
    }

    public function setOrderTransaction(?OrderTransactionEntity $orderTransaction): void
    {
        $this->orderTransaction = $orderTransaction;
    }
}
