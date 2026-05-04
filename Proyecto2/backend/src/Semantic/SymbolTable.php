<?php

declare(strict_types=1);

namespace Proyecto2\Semantic;

final class SymbolTable
{
    /** @var list<Scope> */
    private array $stack = [];

    /** @var list<Symbol> */
    private array $declaredSymbols = [];

    public function __construct()
    {
        $this->stack[] = new Scope('global', 'global', 0, null);
    }

    public function currentScope(): Scope
    {
        return $this->stack[array_key_last($this->stack)];
    }

    public function pushScope(string $name, string $kind): Scope
    {
        $scope = new Scope($name, $kind, count($this->stack), $this->currentScope());
        $this->stack[] = $scope;
        return $scope;
    }

    public function popScope(): void
    {
        if (count($this->stack) > 1) {
            array_pop($this->stack);
        }
    }

    public function isDefinedInCurrentScope(string $name): bool
    {
        return $this->currentScope()->has($name);
    }

    public function define(Symbol $symbol): void
    {
        $scope = $this->currentScope();
        if ($scope->has($symbol->name)) {
            throw new \RuntimeException("Identificador '{$symbol->name}' ya declarado en este ámbito.");
        }

        $scope->define($symbol);
        $this->declaredSymbols[] = $symbol;
    }

    public function resolve(string $name): ?Symbol
    {
        $scope = $this->currentScope();
        while ($scope !== null) {
            $symbol = $scope->resolveLocal($name);
            if ($symbol !== null) {
                return $symbol;
            }

            $scope = $scope->parent;
        }

        return null;
    }

    /**
     * @return list<Symbol>
     */
    public function getDeclaredSymbols(): array
    {
        return $this->declaredSymbols;
    }

    public function globalScope(): Scope
    {
        return $this->stack[0];
    }
}
