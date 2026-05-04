<?php

declare(strict_types=1);

namespace Proyecto2\Reports;

use Proyecto2\Semantic\SymbolTable;

final class SymbolTableReportRenderer
{
    public function renderText(SymbolTable $symbolTable): string
    {
        $lines = ["Identificador\tTipo\tÁmbito\tValor\tLínea\tColumna\tOffset"];
        foreach ($symbolTable->getDeclaredSymbols() as $symbol) {
            $lines[] = implode("\t", [
                $symbol->name,
                (string) $symbol->type,
                $symbol->scopeName,
                $this->formatValue($symbol->value),
                (string) ($symbol->line ?? 0),
                (string) ($symbol->column ?? 0),
                (string) ($symbol->stackOffset ?? 0),
            ]);
        }

        return implode("\n", $lines);
    }

    private function formatValue(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE) ?: '—';
    }
}
