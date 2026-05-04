<?php

declare(strict_types=1);

namespace Proyecto2\Semantic;

final class SemanticModel
{
    /** @param array<string, FunctionSymbol> $functions */
    public function __construct(
        public readonly SymbolTable $symbolTable,
        public readonly array $functions
    ) {
    }

    public function function(string $name): ?FunctionSymbol
    {
        return $this->functions[$name] ?? null;
    }
}
