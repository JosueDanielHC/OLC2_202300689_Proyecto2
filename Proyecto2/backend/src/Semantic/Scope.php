<?php

declare(strict_types=1);

namespace Proyecto2\Semantic;

final class Scope
{
    /** @var array<string, Symbol> */
    private array $symbols = [];

    public function __construct(
        public readonly string $name,
        public readonly string $kind,
        public readonly int $level,
        public readonly ?Scope $parent = null
    ) {
    }

    public function define(Symbol $symbol): void
    {
        $this->symbols[$symbol->name] = $symbol;
    }

    public function has(string $name): bool
    {
        return isset($this->symbols[$name]);
    }

    public function resolveLocal(string $name): ?Symbol
    {
        return $this->symbols[$name] ?? null;
    }

    /**
     * @return array<string, Symbol>
     */
    public function symbols(): array
    {
        return $this->symbols;
    }
}
