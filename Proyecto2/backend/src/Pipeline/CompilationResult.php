<?php

declare(strict_types=1);

namespace Proyecto2\Pipeline;

use Proyecto2\Diagnostics\DiagnosticBag;
use Proyecto2\Codegen\ExecutionResult;
use Proyecto2\Semantic\SemanticModel;

final class CompilationResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $source,
        public readonly string $normalizedSource,
        public readonly DiagnosticBag $diagnostics,
        public readonly ?SemanticModel $semanticModel,
        public readonly string $assembly,
        public readonly array $codegenMetadata,
        public readonly ?ExecutionResult $execution,
        public readonly string $errorReport,
        public readonly string $symbolTableReport
    ) {
    }

    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'asm' => $this->assembly,
            'errors' => $this->diagnostics->toArray(),
            'symbolTable' => $this->semanticModel?->symbolTable->getDeclaredSymbols() ?? [],
            'codegen' => $this->codegenMetadata,
            'execution' => $this->execution?->toArray(),
            'reportErrors' => $this->errorReport,
            'reportSymbols' => $this->symbolTableReport,
            'normalizedSource' => $this->normalizedSource,
            'downloads' => [
                'asm' => 'output/asm/program.s',
                'errors' => 'reporte_errores.txt',
                'symbols' => 'tabla_simbolos.txt',
            ],
        ];
    }
}
