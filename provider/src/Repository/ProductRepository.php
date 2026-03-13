<?php

namespace App\Repository;

/**
 * In-memory product store — no database needed for the demo.
 * Replace with Doctrine/ORM for a real application.
 */
class ProductRepository
{
    /** @var array<int, array<string, mixed>> */
    private array $products = [
        1 => ['id' => 1, 'name' => 'Widget A', 'price' => 9.99,  'stock' => 100],
        2 => ['id' => 2, 'name' => 'Widget B', 'price' => 19.99, 'stock' => 50],
        3 => ['id' => 3, 'name' => 'Gadget X', 'price' => 49.99, 'stock' => 20],
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAll(): array
    {
        return array_values($this->products);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->products[$id] ?? null;
    }
}
