<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A business-rule violation in an inventory operation (insufficient stock, locked stock,
 * invalid state transition…). Rendered as a clear JSON error for the client.
 */
class InventoryException extends RuntimeException
{
    public function __construct(string $message, protected int $status = 422, protected string $errorCode = 'inventory_error', protected array $context = [])
    {
        parent::__construct($message);
    }

    public static function insufficientStock(string $product, int $requested, int $available): self
    {
        return new self(
            "Insufficient stock for {$product}: requested {$requested}, only {$available} available.",
            409,
            'insufficient_stock',
            ['requested' => $requested, 'available' => $available],
        );
    }

    public static function invalidState(string $message): self
    {
        return new self($message, 409, 'invalid_state');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'context' => $this->context ?: null,
        ], $this->status);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
