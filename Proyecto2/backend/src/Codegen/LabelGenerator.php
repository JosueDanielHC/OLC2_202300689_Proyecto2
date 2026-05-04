<?php

declare(strict_types=1);

namespace Proyecto2\Codegen;

final class LabelGenerator
{
    /** @var array<string, int> */
    private array $counters = [];

    public function next(string $prefix): string
    {
        $this->counters[$prefix] = ($this->counters[$prefix] ?? 0) + 1;
        return $prefix . '_' . $this->counters[$prefix];
    }
}
