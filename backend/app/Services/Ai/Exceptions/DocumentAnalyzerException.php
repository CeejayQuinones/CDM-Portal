<?php

namespace App\Services\Ai\Exceptions;

use RuntimeException;
use Throwable;

class DocumentAnalyzerException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $provider,
        public readonly string $model,
        public readonly string $stage,
        public readonly ?string $mimeType = null,
        public readonly ?int $httpStatus = null,
        public readonly int|string|null $providerCode = null,
        public readonly ?string $providerStatus = null,
        public readonly ?string $providerMessage = null,
        public readonly ?string $failureClass = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    /** @return array<string, int|string|null> */
    public function diagnosticContext(): array
    {
        return [
            'exception_class' => $this->failureClass ?? self::class,
            'http_status' => $this->httpStatus,
            'gemini_error_code' => $this->providerCode,
            'gemini_error_status' => $this->providerStatus,
            'provider_message' => $this->providerMessage,
            'configured_model' => $this->model,
            'mime_type' => $this->mimeType,
            'stage' => $this->stage,
        ];
    }
}
