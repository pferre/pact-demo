<?php

declare(strict_types=1);

namespace App\Publisher;

use App\Message\OrderCreatedMessage;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class OrderEventPublisher
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $user,
        private readonly string $password,
        private readonly string $exchange = 'orders',
    ) {}

    public function publishOrderCreated(OrderCreatedMessage $message): void
    {
        $connection = new AMQPStreamConnection(
            $this->host, $this->port, $this->user, $this->password,
        );
        $channel = $connection->channel();
        $channel->exchange_declare($this->exchange, 'fanout', false, true, false);

        $channel->basic_publish(
            new AMQPMessage(
                json_encode($message->toArray(), JSON_THROW_ON_ERROR),
                ['content_type' => 'application/json', 'delivery_mode' => 2],
            ),
            $this->exchange,
        );

        $channel->close();
        $connection->close();
    }
}
