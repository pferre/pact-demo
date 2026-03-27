<?php

declare(strict_types=1);

namespace App\Message;

readonly class OrderCreatedMessage
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $customerId,
        public readonly string $customerEmail,
        public readonly float $totalAmount,
        public readonly string $currency,
        public readonly string $createdAt,
    ) {}

    public function toArray(): array
    {
        return [
            'event' => 'order.created',
            'orderId' => $this->orderId,
            'customerId' => $this->customerId,
            'customerEmail' => $this->customerEmail,
            'totalAmount' => $this->totalAmount,
            'currency' => $this->currency,
            'createdAt' => $this->createdAt,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            orderId: $data['orderId'],
            customerId: $data['customerId'],
            customerEmail: $data['customerEmail'],
            totalAmount: $data['totalAmount'],
            currency: $data['currency'],
            createdAt: $data['createdAt'],
        );
    }
}
