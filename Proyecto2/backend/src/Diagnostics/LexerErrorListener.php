<?php

declare(strict_types=1);

namespace Proyecto2\Diagnostics;

use Antlr\Antlr4\Runtime\Error\Listeners\BaseErrorListener;

final class LexerErrorListener extends BaseErrorListener
{
    public function __construct(private readonly DiagnosticBag $diagnostics)
    {
    }

    public function syntaxError(
        $recognizer,
        $offendingSymbol,
        int $line,
        int $charPositionInLine,
        string $msg,
        $e
    ): void {
        $this->diagnostics->add('Léxico', $msg, $line, $charPositionInLine);
    }
}
