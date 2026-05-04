<?php

declare(strict_types=1);

namespace Proyecto2\Codegen;

final class ExecutionResult
{
    public function __construct(
        public readonly bool $available,
        public readonly bool $ok,
        public readonly string $stdout = '',
        public readonly string $stderr = '',
        public readonly ?string $objectPath = null,
        public readonly ?string $binaryPath = null,
        public readonly ?string $message = null
    ) {
    }

    public function toArray(): array
    {
        return [
            'available' => $this->available,
            'ok' => $this->ok,
            'stdout' => $this->stdout,
            'stderr' => $this->stderr,
            'objectPath' => $this->objectPath,
            'binaryPath' => $this->binaryPath,
            'message' => $this->message,
        ];
    }
}
