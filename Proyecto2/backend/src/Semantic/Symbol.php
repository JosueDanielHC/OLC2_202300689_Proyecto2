<?php

declare(strict_types=1);

namespace Proyecto2\Semantic;

class Symbol
{
    /**
     * @param string $category variable|constant|function|parameter
     * @param string $storage global|stack|builtin
     */
    public function __construct(
        public string $name,
        public Type $type,
        public string $category,
        public string $scopeName,
        public int $scopeLevel,
        public ?int $line = null,
        public ?int $column = null,
        public mixed $value = null,
        public ?int $stackOffset = null,
        public string $storage = 'stack',
        public ?string $ownerFunction = null,
        public bool $mutable = true
    ) {
    }
}
