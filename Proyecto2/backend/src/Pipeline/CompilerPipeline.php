<?php

declare(strict_types=1);

namespace Proyecto2\Pipeline;

use Proyecto2\Codegen\AsmRunner;
use Proyecto2\Codegen\Arm64CodegenVisitor;
use Proyecto2\Reports\ErrorReportRenderer;
use Proyecto2\Reports\SymbolTableReportRenderer;
use Proyecto2\Semantic\SemanticVisitor2;
use Proyecto2\Support\Paths;

final class CompilerPipeline
{
    public function __construct(
        private readonly ParsePipeline $parsePipeline = new ParsePipeline(),
        private readonly ErrorReportRenderer $errorReports = new ErrorReportRenderer(),
        private readonly SymbolTableReportRenderer $symbolReports = new SymbolTableReportRenderer(),
        private readonly AsmRunner $asmRunner = new AsmRunner()
    ) {
    }

    public function compile(string $source): CompilationResult
    {
        $parseResult = $this->parsePipeline->parse($source);
        $diagnostics = $parseResult->diagnostics;

        $semantic = new SemanticVisitor2($diagnostics);
        $semanticModel = null;
        $assembly = '';
        $codegenMetadata = [];
        $execution = null;

        if ($parseResult->tree !== null) {
            $semantic->visit($parseResult->tree);
            $semanticModel = $semantic->model();
        }

        if (!$diagnostics->hasErrors() && $parseResult->tree !== null && $semanticModel !== null) {
            $codegen = new Arm64CodegenVisitor($semanticModel);
            $codegenResult = $codegen->generate($parseResult->tree);
            $assembly = $codegenResult->assembly;
            $codegenMetadata = $codegenResult->metadata;
            Paths::ensureOutputDirectory();
            file_put_contents(Paths::outputAsmPath(), $assembly);
            $limitations = $codegenMetadata['limitations'] ?? [];
            $pointerBlocked = is_array($limitations)
                && array_filter(
                    $limitations,
                    static fn (mixed $item): bool => is_string($item) && str_contains($item, 'punteros')
                ) !== [];

            if (!$pointerBlocked) {
                $execution = $this->asmRunner->run(Paths::outputAsmPath());
            }
        }

        $errorReport = $this->errorReports->renderText($diagnostics);
        $symbolReport = $semanticModel !== null
            ? $this->symbolReports->renderText($semanticModel->symbolTable)
            : "Identificador\tTipo\tÁmbito\tValor\tLínea\tColumna\tOffset";

        return new CompilationResult(
            !$diagnostics->hasErrors(),
            $parseResult->source,
            $parseResult->normalizedSource,
            $diagnostics,
            $semanticModel,
            $assembly,
            $codegenMetadata,
            $execution,
            $errorReport,
            $symbolReport
        );
    }
}
