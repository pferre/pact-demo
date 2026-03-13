<?php

namespace App\Controller;

use App\Service\ProductServiceClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/orders', name: 'orders_')]
class OrderController extends AbstractController
{
    public function __construct(
        private readonly ProductServiceClient $productClient
    ) {}

    /**
     * Create an order — fetches product info from the Provider service.
     */
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(): JsonResponse
    {
        // In a real app this would come from the request body
        $productId = 1;

        $product = $this->productClient->getProduct($productId);

        if (!$product) {
            return $this->json(['error' => 'Product not found'], 404);
        }

        return $this->json([
            'order_id'   => uniqid('ORD-'),
            'product_id' => $product['id'],
            'name'       => $product['name'],
            'price'      => $product['price'],
            'status'     => 'created',
        ], 201);
    }

    /**
     * Health check endpoint.
     */
    #[Route('/health', name: 'health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return $this->json(['service' => 'consumer', 'status' => 'ok']);
    }
}
