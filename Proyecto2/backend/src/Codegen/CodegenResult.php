<?php

declare(strict_types=1);

namespace Proyecto2\Codegen;

final class CodegenResult
{
    public function __construct(
        public readonly string $assembly,
        public readonly ?string $entryFunction = null,
        public readonly array $metadata = []
    ) {
    }
}
