<?php

declare(strict_types=1);

namespace Proyecto2\Reports;

use Proyecto2\Diagnostics\DiagnosticBag;

final class ErrorReportRenderer
{
    public function renderText(DiagnosticBag $diagnostics): string
    {
        $lines = ["#\tTipo\tDescripción\tLínea\tColumna"];
        foreach ($diagnostics->all() as $index => $item) {
            $lines[] = implode("\t", [
                (string) ($index + 1),
                $item->type,
                $item->message,
                (string) $item->line,
                (string) $item->column,
            ]);
        }

        return implode("\n", $lines);
    }
}
