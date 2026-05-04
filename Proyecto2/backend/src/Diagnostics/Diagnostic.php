<?php

declare(strict_types=1);

namespace Proyecto2\Diagnostics;

final class Diagnostic
{
    public function __construct(
        public readonly string $type,
        public readonly string $message,
        public readonly int $line = 0,
        public readonly int $column = 0
    ) {
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'message' => $this->message,
            'line' => $this->line,
            'column' => $this->column,
        ];
    }
}
