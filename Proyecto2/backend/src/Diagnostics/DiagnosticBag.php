<?php

declare(strict_types=1);

namespace Proyecto2\Diagnostics;

final class DiagnosticBag
{
    /** @var list<Diagnostic> */
    private array $items = [];

    public function add(string $type, string $message, int $line = 0, int $column = 0): void
    {
        $this->items[] = new Diagnostic($type, $message, $line, $column);
    }

    public function addDiagnostic(Diagnostic $diagnostic): void
    {
        $this->items[] = $diagnostic;
    }

    public function merge(self $other): void
    {
        foreach ($other->all() as $diagnostic) {
            $this->items[] = $diagnostic;
        }
    }

    public function hasErrors(): bool
    {
        return $this->items !== [];
    }

    /**
     * @return list<Diagnostic>
     */
    public function all(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function toArray(): array
    {
        return array_map(static fn (Diagnostic $item): array => $item->toArray(), $this->items);
    }
}
