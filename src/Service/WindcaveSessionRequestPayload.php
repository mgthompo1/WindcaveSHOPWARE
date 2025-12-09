<?php

declare(strict_types=1);

namespace Windcave\Service;

class WindcaveSessionRequestPayload
{
    public function __construct(
        public readonly string $username,
        public readonly string $apiKey,
        public readonly float $amount,
        public readonly string $currency,
        public readonly string $merchantReference,
        public readonly string $language,
        public readonly string $approvedUrl,
        public readonly string $declinedUrl,
        public readonly string $cancelledUrl,
        public readonly string $notificationUrl,
        public readonly bool $testMode,
        public readonly ?string $customerEmail = null,
        public readonly ?string $customerPhone = null,
        public readonly ?string $customerHomePhone = null,
        /** @var array<string,mixed>|null */
        public readonly ?array $billingAddress = null,
        /** @var array<string,mixed>|null */
        public readonly ?array $shippingAddress = null,
        /** @var array<string,mixed>|null */
        public readonly ?array $threeDS = null
    ) {
    }

    public function asArray(): array
    {
        $payload = [
            'type' => 'purchase',
            'amount' => number_format($this->amount, 2, '.', ''),
            'currency' => $this->currency,
            'merchantReference' => $this->merchantReference,
            'language' => $this->language,
            'callbackUrls' => [
                'approved' => $this->approvedUrl,
                'declined' => $this->declinedUrl,
                'cancelled' => $this->cancelledUrl,
            ],
            'notificationUrl' => $this->notificationUrl,
        ];

        $customer = [];
        if ($this->customerEmail) {
            $customer['email'] = $this->customerEmail;
        }
        if ($this->customerPhone) {
            $customer['phoneNumber'] = $this->customerPhone;
        }
        if ($this->customerHomePhone) {
            $customer['homePhoneNumber'] = $this->customerHomePhone;
        }
        if ($this->billingAddress) {
            $customer['billing'] = $this->billingAddress;
        }
        if ($this->shippingAddress) {
            $customer['shipping'] = $this->shippingAddress;
        }
        if (!empty($customer)) {
            $payload['customer'] = $customer;
        }

        if ($this->threeDS) {
            $payload['threeds'] = $this->threeDS;
        }

        return $payload;
    }
}
