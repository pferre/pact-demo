<?php

declare(strict_types=1);

namespace App\EventHandler;

use App\Message\OrderCreatedMessage;

/**
 * Handles the order.created event consumed from RabbitMQ.
 * In the PACT provider verification test, this handler is called
 * directly with the pact payload — no RabbitMQ connection needed.
 */
class OrderCreatedHandler
{
    public function handle(OrderCreatedMessage $message): void
    {
        // Real implementation would update stock, trigger fulfilment etc.
        error_log(
            sprintf(
                '[ProductService] Received order.created — orderId: %s, customer: %s',
                $message->orderId,
                $message->customerEmail,
            ),
        );
    }
}
