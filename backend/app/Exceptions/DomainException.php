<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Base for business-rule failures that should surface to the client as a
 * structured 4xx rather than a 500. Rendering is handled here so every domain
 * error has the same response shape.
 */
abstract class DomainException extends RuntimeException
{
    /** @var array<string, mixed> */
    protected array $context = [];

    protected int $statusCode = 422;

    protected string $errorCode = 'domain_error';

    /** @param array<string, mixed> $context */
    public function __construct(string $message, array $context = [])
    {
        parent::__construct($message);
        $this->context = $context;
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => $this->errorCode,
            'context' => $this->context,
        ], $this->statusCode);
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return $this->context;
    }
}
