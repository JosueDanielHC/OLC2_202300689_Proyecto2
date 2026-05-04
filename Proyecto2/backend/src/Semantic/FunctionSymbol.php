<?php

declare(strict_types=1);

namespace Proyecto2\Semantic;

final class FunctionSymbol extends Symbol
{
    /**
     * @param list<Type> $parameterTypes
     * @param list<string> $parameterNames
     * @param list<Type> $returnTypes
     */
    public function __construct(
        string $name,
        Type $type,
        string $scopeName,
        int $scopeLevel,
        ?int $line,
        ?int $column,
        public array $parameterTypes,
        public array $parameterNames,
        public array $returnTypes,
        public mixed $context = null,
        public int $frameSize = 0
    ) {
        parent::__construct(
            $name,
            $type,
            'function',
            $scopeName,
            $scopeLevel,
            $line,
            $column,
            null,
            null,
            'global',
            $name,
            false
        );
    }
}
