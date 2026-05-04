<?php

declare(strict_types=1);

namespace Proyecto2\Pipeline;

use Proyecto2\Diagnostics\DiagnosticBag;

final class ParseResult
{
    public function __construct(
        public readonly string $source,
        public readonly string $normalizedSource,
        public readonly mixed $tree,
        public readonly mixed $tokens,
        public readonly DiagnosticBag $diagnostics
    ) {
    }
}
