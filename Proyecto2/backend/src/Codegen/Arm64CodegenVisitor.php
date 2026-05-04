<?php

declare(strict_types=1);

namespace Proyecto2\Codegen;

use Proyecto2\Semantic\BuiltinRegistry;
use Proyecto2\Semantic\FunctionSymbol;
use Proyecto2\Semantic\SemanticModel;
use Proyecto2\Semantic\Symbol;
use Proyecto2\Semantic\Type;
use Proyecto2\Semantic\TypeRules;

final class Arm64CodegenVisitor extends \GolampiBaseVisitor
{
    private AsmBuilder $builder;
    private LabelGenerator $labels;
    private StackFrameLayout $frames;
    private RuntimeEmitter $runtime;

    private ?FunctionSymbol $currentFunction = null;

    /** @var array<string, string> */
    private array $stringLiterals = [];

    /** @var array<string, string> */
    private array $floatLiterals = [];

    /** @var list<string> */
    private array $loopStartLabels = [];

    /** @var list<string> */
    private array $loopContinueLabels = [];

    /** @var list<string> */
    private array $loopEndLabels = [];

    /** @var list<string> */
    private array $limitations = [];

    public function __construct(private readonly SemanticModel $model)
    {
        $this->builder = new AsmBuilder();
        $this->labels = new LabelGenerator();
        $this->frames = new StackFrameLayout();
        $this->runtime = new RuntimeEmitter();
    }

    public function generate(\Context\ProgramContext $context): CodegenResult
    {
        $this->runtime->emitPreamble($this->builder);
        $this->scanPointerUsage($context);
        $this->emitGlobalDeclarations($context);

        $this->builder->addText('.global _start');
        $this->builder->addText('_start:');
        $this->builder->addComment('Entrada principal del compilador');
        $this->builder->addText('bl main');
        $this->runtime->emitExitSequence($this->builder);
        $this->builder->addText('');

        foreach ($this->normalizeList($context->topLevelDecl(null)) as $decl) {
            if ($decl->functionDecl() !== null) {
                $this->emitFunction($decl->functionDecl());
            }
        }

        $this->runtime->emitHelpers($this->builder);

        return new CodegenResult($this->builder->render(), 'main', [
            'limitations' => $this->limitations,
        ]);
    }

    private function scanPointerUsage(\Context\ProgramContext $context): void
    {
        if (str_contains($context->getText(), '&') || str_contains($context->getText(), '*')) {
            $this->registerLimitation(
                'Los punteros se mantienen validados semanticamente, pero la ejecución ARM64 de punteros esta bloqueada hasta completar soporte correcto.'
            );
        }
    }

    private function emitGlobalDeclarations(\Context\ProgramContext $context): void
    {
        foreach ($this->normalizeList($context->topLevelDecl(null)) as $decl) {
            if ($decl->varDecl() === null) {
                continue;
            }

            $varDecl = $decl->varDecl();
            $identifiers = $this->normalizeList($varDecl->identifierList()?->IDENTIFIER(null));
            $expressions = $varDecl->expressionList() !== null
                ? $this->normalizeList($varDecl->expressionList()->expression(null))
                : [];

            foreach ($identifiers as $index => $identifier) {
                $name = $identifier->getText();
            $symbol = $this->resolveCurrentFunctionSymbol($name) ?? $this->model->symbolTable->resolve($name);
                if ($symbol === null || $symbol->storage !== 'global') {
                    continue;
                }

                $expression = $expressions[$index] ?? null;
                $this->emitGlobalStorage($symbol, $expression);
            }
        }
    }

    private function emitGlobalStorage(Symbol $symbol, mixed $expression): void
    {
        $type = $symbol->type;

        if ($type->name === Type::STRING) {
            if ($expression !== null) {
                $label = $this->literalLabelFromExpression($expression);
                if ($label !== null) {
                    $this->builder->addData($symbol->name . ': .quad ' . $label);
                    return;
                }
            }

            $this->builder->addData($symbol->name . ': .quad 0');
            return;
        }

        if ($type->name === Type::BOOL) {
            $value = $this->constantBoolFromExpression($expression);
            $this->builder->addData($symbol->name . ': .quad ' . ($value ? 1 : 0));
            return;
        }

        if ($type->name === Type::FLOAT32) {
            $value = $this->constantFloatFromExpression($expression) ?? '0.0';
            $this->builder->addData($symbol->name . ': .float ' . $value);
            return;
        }

        if ($type->name === Type::INT32 || $type->name === Type::RUNE) {
            $value = $this->constantIntFromExpression($expression) ?? 0;
            $this->builder->addData($symbol->name . ': .quad ' . $value);
            return;
        }

        if ($type->isArray()) {
            $size = max(8, $type->sizeInBytes());
            $this->builder->addBss($symbol->name . ': .skip ' . $size);
            $this->limitations[] = 'El codegen de arreglos se limita a almacenamiento lineal básico.';
            return;
        }

        $this->builder->addData($symbol->name . ': .quad 0');
    }

    private function emitFunction(\Context\FunctionDeclContext $context): void
    {
        $name = $context->IDENTIFIER()?->getText() ?? '';
        $function = $this->model->function($name);
        if ($function === null) {
            return;
        }

        $previousFunction = $this->currentFunction;
        $this->currentFunction = $function;

        $frameSize = $this->frames->frameSize($function);
        $endLabel = $this->labels->next($name . '_end');

        $this->builder->addComment("Inicio de función {$name}");
        $this->builder->addText($name . ':');
        $this->builder->addText('stp x29, x30, [sp, #-16]!');
        $this->builder->addText('mov x29, sp');
        if ($frameSize > 0) {
            $this->builder->addText("sub sp, sp, #{$frameSize}");
        }

        $this->storeParameters($function);

        if ($context->block() !== null) {
            $this->emitBlockStatements($context->block(), $endLabel);
        }

        $this->builder->addText($endLabel . ':');
        if ($frameSize > 0) {
            $this->builder->addText("add sp, sp, #{$frameSize}");
        }
        $this->builder->addText('ldp x29, x30, [sp], #16');
        $this->builder->addText('ret');
        $this->builder->addText('');

        $this->currentFunction = $previousFunction;
    }

    private function storeParameters(FunctionSymbol $function): void
    {
        foreach ($function->parameterNames as $index => $parameterName) {
            if ($index > 7) {
                $this->limitations[] = 'Solo se soportan 8 parámetros en registros x0-x7.';
                break;
            }

            $symbol = $this->resolveCurrentFunctionSymbol($parameterName);
            if ($symbol === null || $symbol->stackOffset === null) {
                continue;
            }

            $register = 'x' . $index;
            $this->builder->addComment("Guardar parámetro {$parameterName}");
            if ($symbol->type->name === Type::FLOAT32) {
                $this->builder->addText("str s{$index}, [x29, #-{$symbol->stackOffset}]");
            } else {
                $this->builder->addText("str {$register}, [x29, #-{$symbol->stackOffset}]");
            }
        }
    }

    private function emitBlockStatements(\Context\BlockContext $block, string $functionEndLabel): void
    {
        foreach ($this->normalizeList($block->statement(null)) as $statement) {
            $this->emitStatement($statement, $functionEndLabel);
        }
    }

    private function emitStatement($statement, string $functionEndLabel): void
    {
        if ($statement->varDecl() !== null) {
            $this->emitVarDecl($statement->varDecl());
        } elseif ($statement->shortVarDecl() !== null) {
            $this->emitShortVarDecl($statement->shortVarDecl());
        } elseif ($statement->assignment() !== null) {
            $this->emitAssignment($statement->assignment());
        } elseif ($statement->ifStmt() !== null) {
            $this->emitIfStmt($statement->ifStmt(), $functionEndLabel);
        } elseif ($statement->forStmt() !== null) {
            $this->emitForStmt($statement->forStmt(), $functionEndLabel);
        } elseif ($statement->switchStmt() !== null) {
            $this->emitSwitchStmt($statement->switchStmt(), $functionEndLabel);
        } elseif ($statement->returnStmt() !== null) {
            $this->emitReturnStmt($statement->returnStmt(), $functionEndLabel);
        } elseif ($statement->expressionStmt() !== null) {
            $this->emitExpressionStmt($statement->expressionStmt());
        } elseif ($statement->breakStmt() !== null) {
            $target = end($this->loopEndLabels) ?: $functionEndLabel;
            $this->builder->addText("b {$target}");
        } elseif ($statement->continueStmt() !== null) {
            $target = end($this->loopContinueLabels) ?: (end($this->loopStartLabels) ?: $functionEndLabel);
            $this->builder->addText("b {$target}");
        } elseif ($statement->block() !== null) {
            $this->emitBlockStatements($statement->block(), $functionEndLabel);
        }
    }

    private function emitExpressionStmt(\Context\ExpressionStmtContext $context): void
    {
        $expression = $context->expression();
        $functionCall = $expression !== null ? $this->extractFunctionCall($expression) : null;
        if ($functionCall !== null) {
            $name = $functionCall->qualifiedIdentifier()?->getText() ?? '';
            if ($name === BuiltinRegistry::PRINTLN) {
                $this->emitPrintlnCall($functionCall);
                return;
            }
        }

        $this->emitExpression($expression);
    }

    private function emitVarDecl(\Context\VarDeclContext $context): void
    {
        $identifiers = $this->normalizeList($context->identifierList()?->IDENTIFIER(null));
        $expressions = $context->expressionList() !== null ? $this->normalizeList($context->expressionList()->expression(null)) : [];

        foreach ($identifiers as $index => $identifier) {
            $symbol = $this->resolveCurrentFunctionSymbol($identifier->getText());
            if ($symbol === null) {
                continue;
            }

            $expression = $expressions[$index] ?? null;
            if ($symbol->type->isArray()) {
                $this->emitArrayVariableInitialization($symbol, $expression);
                continue;
            }
            if ($expression !== null) {
                $this->emitExpression($expression);
                if ($symbol->type->name === Type::FLOAT32 && $this->typeOfExpression($expression)->name !== Type::FLOAT32) {
                    $this->builder->addText('scvtf s0, w0');
                }
            } else {
                $this->emitDefaultValue($symbol->type);
            }

            $this->storeExpressionResult($symbol);
        }
    }

    private function emitShortVarDecl(\Context\ShortVarDeclContext $context): void
    {
        $identifiers = $this->normalizeList($context->identifierList()?->IDENTIFIER(null));
        $expressions = $this->normalizeList($context->expressionList()?->expression(null));

        foreach ($identifiers as $index => $identifier) {
            if (!isset($expressions[$index])) {
                continue;
            }
            $symbol = $this->resolveCurrentFunctionSymbol($identifier->getText());
            if ($symbol === null) {
                continue;
            }

            if ($symbol->type->isArray()) {
                $this->emitArrayVariableInitialization($symbol, $expressions[$index]);
                continue;
            }
            $this->emitExpression($expressions[$index]);
            $this->storeExpressionResult($symbol);
        }
    }

    private function emitAssignment(\Context\AssignmentContext $context): void
    {
        $target = $context->expression(0);
        $source = $context->expression(1);
        $operator = $context->assignOp()?->getText() ?? '=';
        $symbol = $target !== null ? $this->symbolFromExpression($target) : null;

        if ($target === null || $source === null || $symbol === null) {
            return;
        }

        if ($this->isPointerAssignment($target)) {
            $this->registerLimitation('La escritura por punteros aun no esta soportada en runtime ARM64; se bloquea para evitar resultados incorrectos.');
            return;
        }

        $arrayAccess = $this->extractArrayAccess($target);
        if ($arrayAccess !== null) {
            $this->emitArrayElementAssignment($arrayAccess, $symbol, $source);
            return;
        }

        $leftType = $this->typeOfSymbol($symbol);
        if ($operator === '=') {
            $this->emitExpression($source);
            if ($leftType->name === Type::FLOAT32 && $this->typeOfExpression($source)->name !== Type::FLOAT32) {
                $this->builder->addText('scvtf s0, w0');
            }
            $this->storeExpressionResult($symbol);
            return;
        }

        if ($leftType->name === Type::STRING && $operator === '+=') {
            $this->loadSymbolToX0($symbol);
            $this->builder->addText('mov x9, x0');
            $this->emitExpression($source);
            $this->builder->addText('mov x1, x0');
            $this->builder->addText('mov x0, x9');
            $this->builder->addText('bl __concat_strings');
            $this->storeExpressionResult($symbol);
            return;
        }

        $rightType = $this->typeOfExpression($source);
        $resultType = match ($operator) {
            '+=' => TypeRules::arithmeticResult('+', $leftType, $rightType),
            '-=' => TypeRules::arithmeticResult('-', $leftType, $rightType),
            '*=' => TypeRules::arithmeticResult('*', $leftType, $rightType),
            '/=' => TypeRules::arithmeticResult('/', $leftType, $rightType),
            '%=' => TypeRules::arithmeticResult('%', $leftType, $rightType),
            default => null,
        };

        if ($resultType?->name === Type::FLOAT32) {
            $this->loadSymbolValue($symbol);
            if ($leftType->name === Type::FLOAT32) {
                $this->builder->addText('fmov s1, s0');
            } else {
                $this->builder->addText('mov w9, w0');
            }

            $this->emitExpression($source);
            if ($leftType->name !== Type::FLOAT32) {
                $this->builder->addText('scvtf s1, w9');
            }
            if ($rightType->name !== Type::FLOAT32) {
                $this->builder->addText('scvtf s0, w0');
            }

            $instruction = match ($operator) {
                '+=' => 'fadd',
                '-=' => 'fsub',
                '*=' => 'fmul',
                '/=' => 'fdiv',
                default => null,
            };

            if ($instruction !== null) {
                $this->builder->addText("{$instruction} s0, s1, s0");
                $this->storeExpressionResult($symbol);
            }
            return;
        }

        $this->loadSymbolToX0($symbol);
        $this->builder->addText('mov x9, x0');
        $this->emitExpression($source);

        if ($operator === '%=') {
            $this->emitModuloSequence('x9', 'x0');
            $this->storeExpressionResult($symbol);
            return;
        }

        $instruction = match ($operator) {
            '+=' => 'add',
            '-=' => 'sub',
            '*=' => 'mul',
            '/=' => 'sdiv',
            default => null,
        };

        if ($instruction === null) {
            return;
        }

        $this->builder->addText("{$instruction} x0, x9, x0");
        $this->storeExpressionResult($symbol);
    }

    private function emitIfStmt(\Context\IfStmtContext $context, string $functionEndLabel): void
    {
        $elseLabel = $this->labels->next('else');
        $endLabel = $this->labels->next('endif');

        if ($context->simpleStmt() !== null) {
            $this->emitSimpleStmt($context->simpleStmt());
        }

        $this->emitExpression($context->expression());
        $this->builder->addText('cmp x0, #0');
        $this->builder->addText("b.eq {$elseLabel}");

        $blocks = $this->normalizeList($context->block(null));
        if (isset($blocks[0])) {
            $this->emitBlockStatements($blocks[0], $functionEndLabel);
        }
        $this->builder->addText("b {$endLabel}");

        $this->builder->addText($elseLabel . ':');
        if ($context->ifStmt() !== null) {
            $this->emitIfStmt($context->ifStmt(), $functionEndLabel);
        } elseif (isset($blocks[1])) {
            $this->emitBlockStatements($blocks[1], $functionEndLabel);
        }

        $this->builder->addText($endLabel . ':');
    }

    private function emitForStmt(\Context\ForStmtContext $context, string $functionEndLabel): void
    {
        $startLabel = $this->labels->next('for_start');
        $continueLabel = $this->labels->next('for_continue');
        $endLabel = $this->labels->next('for_end');

        if ($context->forClause() !== null) {
            $simpleStmts = $this->normalizeList($context->forClause()->simpleStmt(null));
            if (isset($simpleStmts[0])) {
                $this->emitSimpleStmt($simpleStmts[0]);
            }

            $this->builder->addText($startLabel . ':');
            if ($context->forClause()->expression() !== null) {
                $this->emitExpression($context->forClause()->expression());
                $this->builder->addText('cmp x0, #0');
                $this->builder->addText("b.eq {$endLabel}");
            }

            $this->loopStartLabels[] = $startLabel;
            $this->loopContinueLabels[] = $continueLabel;
            $this->loopEndLabels[] = $endLabel;
            $this->emitBlockStatements($context->block(), $functionEndLabel);
            array_pop($this->loopStartLabels);
            array_pop($this->loopContinueLabels);
            array_pop($this->loopEndLabels);

            $this->builder->addText($continueLabel . ':');
            if (isset($simpleStmts[1])) {
                $this->emitSimpleStmt($simpleStmts[1]);
            }
            $this->builder->addText("b {$startLabel}");
            $this->builder->addText($endLabel . ':');
            return;
        }

        $this->builder->addText($startLabel . ':');
        if ($context->expression() !== null) {
            $this->emitExpression($context->expression());
            $this->builder->addText('cmp x0, #0');
            $this->builder->addText("b.eq {$endLabel}");
        }

        $this->loopStartLabels[] = $startLabel;
        $this->loopContinueLabels[] = $startLabel;
        $this->loopEndLabels[] = $endLabel;
        $this->emitBlockStatements($context->block(), $functionEndLabel);
        array_pop($this->loopStartLabels);
        array_pop($this->loopContinueLabels);
        array_pop($this->loopEndLabels);

        $this->builder->addText("b {$startLabel}");
        $this->builder->addText($endLabel . ':');
    }

    private function emitSwitchStmt(\Context\SwitchStmtContext $context, string $functionEndLabel): void
    {
        $endLabel = $this->labels->next('switch_end');
        $defaultLabel = $context->defaultClause() !== null ? $this->labels->next('switch_default') : $endLabel;

        $this->emitExpression($context->expression());
        $this->builder->addText('mov x19, x0');

        $caseLabels = [];
        foreach ($this->normalizeList($context->caseClause(null)) as $caseClause) {
            $label = $this->labels->next('switch_case');
            $caseLabels[] = [$caseClause, $label];
            foreach ($this->normalizeList($caseClause->expressionList()?->expression(null)) as $expression) {
                $this->emitExpression($expression);
                $this->builder->addText('cmp x19, x0');
                $this->builder->addText("b.eq {$label}");
            }
        }

        $this->builder->addText("b {$defaultLabel}");

        foreach ($caseLabels as [$caseClause, $label]) {
            $this->builder->addText($label . ':');
            $this->loopEndLabels[] = $endLabel;
            foreach ($this->normalizeList($caseClause->statement(null)) as $statement) {
                $this->emitStatement($statement, $functionEndLabel);
            }
            array_pop($this->loopEndLabels);
            $this->builder->addText("b {$endLabel}");
        }

        if ($context->defaultClause() !== null) {
            $this->builder->addText($defaultLabel . ':');
            foreach ($this->normalizeList($context->defaultClause()->statement(null)) as $statement) {
                $this->emitStatement($statement, $functionEndLabel);
            }
        }

        $this->builder->addText($endLabel . ':');
    }

    private function emitReturnStmt(\Context\ReturnStmtContext $context, string $functionEndLabel): void
    {
        $expressions = $context->expressionList() !== null ? $this->normalizeList($context->expressionList()->expression(null)) : [];
        if (isset($expressions[0])) {
            $this->emitExpression($expressions[0]);
            if ($this->currentFunction?->type->name === Type::FLOAT32 && $this->typeOfExpression($expressions[0])->name !== Type::FLOAT32) {
                $this->builder->addText('scvtf s0, w0');
            }
        } else {
            $this->builder->addText('mov x0, #0');
        }
        $this->builder->addText("b {$functionEndLabel}");
    }

    private function emitSimpleStmt(\Context\SimpleStmtContext $context): void
    {
        if ($context->shortVarDecl() !== null) {
            $this->emitShortVarDecl($context->shortVarDecl());
        } elseif ($context->varDecl() !== null) {
            $this->emitVarDecl($context->varDecl());
        } elseif ($context->assignment() !== null) {
            $this->emitAssignment($context->assignment());
        } elseif ($context->expressionStmt() !== null) {
            $this->emitExpressionStmt($context->expressionStmt());
        } elseif ($context->incDecStmt() !== null) {
            $expr = $context->incDecStmt()->expression();
            $symbol = $expr !== null ? $this->symbolFromExpression($expr) : null;
            if ($symbol !== null) {
                if ($symbol->type->name === Type::FLOAT32) {
                    $this->loadSymbolValue($symbol);
                    $oneLabel = $this->internFloatLiteral('1.0');
                    $this->builder->addText("adrp x10, {$oneLabel}");
                    $this->builder->addText("add x10, x10, :lo12:{$oneLabel}");
                    $this->builder->addText('ldr s1, [x10]');
                    $instruction = str_contains($context->incDecStmt()->getText(), '++') ? 'fadd' : 'fsub';
                    $this->builder->addText("{$instruction} s0, s0, s1");
                } else {
                    $this->loadSymbolToX0($symbol);
                    $instruction = str_contains($context->incDecStmt()->getText(), '++') ? 'add' : 'sub';
                    $this->builder->addText("{$instruction} x0, x0, #1");
                }
                $this->storeExpressionResult($symbol);
            }
        }
    }

    private function emitExpression(?\Context\ExpressionContext $context): void
    {
        if ($context === null) {
            $this->builder->addText('mov x0, #0');
            return;
        }

        $this->emitLogicalOr($context->logicalOr());
    }

    private function emitLogicalOr(?\Context\LogicalOrContext $context): void
    {
        if ($context === null) {
            $this->builder->addText('mov x0, #0');
            return;
        }

        $parts = $this->normalizeList($context->logicalAnd(null));
        $this->emitLogicalAnd($parts[0] ?? null);
        if (count($parts) === 1) {
            return;
        }

        $trueLabel = $this->labels->next('or_true');
        $endLabel = $this->labels->next('or_end');
        foreach ($parts as $index => $part) {
            if ($index === 0) {
                $this->builder->addText('cmp x0, #0');
                $this->builder->addText("b.ne {$trueLabel}");
                continue;
            }
            $this->emitLogicalAnd($part);
            $this->builder->addText('cmp x0, #0');
            $this->builder->addText("b.ne {$trueLabel}");
        }
        $this->builder->addText('mov x0, #0');
        $this->builder->addText("b {$endLabel}");
        $this->builder->addText($trueLabel . ':');
        $this->builder->addText('mov x0, #1');
        $this->builder->addText($endLabel . ':');
    }

    private function emitLogicalAnd(?\Context\LogicalAndContext $context): void
    {
        if ($context === null) {
            $this->builder->addText('mov x0, #0');
            return;
        }

        $parts = $this->normalizeList($context->equality(null));
        $this->emitEquality($parts[0] ?? null);
        if (count($parts) === 1) {
            return;
        }

        $falseLabel = $this->labels->next('and_false');
        $endLabel = $this->labels->next('and_end');
        foreach ($parts as $index => $part) {
            if ($index === 0) {
                $this->builder->addText('cmp x0, #0');
                $this->builder->addText("b.eq {$falseLabel}");
                continue;
            }
            $this->emitEquality($part);
            $this->builder->addText('cmp x0, #0');
            $this->builder->addText("b.eq {$falseLabel}");
        }
        $this->builder->addText('mov x0, #1');
        $this->builder->addText("b {$endLabel}");
        $this->builder->addText($falseLabel . ':');
        $this->builder->addText('mov x0, #0');
        $this->builder->addText($endLabel . ':');
    }

    private function emitEquality(?\Context\EqualityContext $context): void
    {
        if ($context === null) {
            $this->builder->addText('mov x0, #0');
            return;
        }

        $parts = $this->normalizeList($context->comparison(null));
        $currentType = $this->typeOfComparison($parts[0] ?? null);
        $this->emitComparison($parts[0] ?? null);
        for ($index = 1; $index < count($parts); $index++) {
            $operator = $context->getChild(($index * 2) - 1)?->getText() ?? '==';
            $rightType = $this->typeOfComparison($parts[$index]);
            if ($currentType->name === Type::FLOAT32) {
                $this->builder->addText('fmov s1, s0');
            } else {
                $this->builder->addText('mov x9, x0');
            }
            $this->emitComparison($parts[$index]);
            if ($currentType->name === Type::FLOAT32 || $rightType->name === Type::FLOAT32) {
                if ($currentType->name !== Type::FLOAT32) {
                    $this->builder->addText('scvtf s1, w9');
                }
                if ($rightType->name !== Type::FLOAT32) {
                    $this->builder->addText('scvtf s0, w0');
                }
                $this->builder->addText('fcmp s1, s0');
            } else {
                $this->builder->addText('cmp x9, x0');
            }
            $trueLabel = $this->labels->next('eq_true');
            $endLabel = $this->labels->next('eq_end');
            $branch = $operator === '!=' ? 'b.ne' : 'b.eq';
            $this->builder->addText("{$branch} {$trueLabel}");
            $this->builder->addText('mov x0, #0');
            $this->builder->addText("b {$endLabel}");
            $this->builder->addText($trueLabel . ':');
            $this->builder->addText('mov x0, #1');
            $this->builder->addText($endLabel . ':');
        }
    }

    private function emitComparison(?\Context\ComparisonContext $context): void
    {
        if ($context === null) {
            $this->builder->addText('mov x0, #0');
            return;
        }

        $parts = $this->normalizeList($context->addition(null));
        $currentType = $this->typeOfAddition($parts[0] ?? null);
        $this->emitAddition($parts[0] ?? null);
        for ($index = 1; $index < count($parts); $index++) {
            $operator = $context->getChild(($index * 2) - 1)?->getText() ?? '>';
            $rightType = $this->typeOfAddition($parts[$index]);
            if ($currentType->name === Type::FLOAT32) {
                $this->builder->addText('fmov s1, s0');
            } else {
                $this->builder->addText('mov x9, x0');
            }
            $this->emitAddition($parts[$index]);
            if ($currentType->name === Type::FLOAT32 || $rightType->name === Type::FLOAT32) {
                if ($currentType->name !== Type::FLOAT32) {
                    $this->builder->addText('scvtf s1, w9');
                }
                if ($rightType->name !== Type::FLOAT32) {
                    $this->builder->addText('scvtf s0, w0');
                }
                $this->builder->addText('fcmp s1, s0');
            } else {
                $this->builder->addText('cmp x9, x0');
            }
            $trueLabel = $this->labels->next('cmp_true');
            $endLabel = $this->labels->next('cmp_end');
            $branch = match ($operator) {
                '>' => 'b.gt',
                '>=' => 'b.ge',
                '<' => 'b.lt',
                '<=' => 'b.le',
                default => 'b.eq',
            };
            $this->builder->addText("{$branch} {$trueLabel}");
            $this->builder->addText('mov x0, #0');
            $this->builder->addText("b {$endLabel}");
            $this->builder->addText($trueLabel . ':');
            $this->builder->addText('mov x0, #1');
            $this->builder->addText($endLabel . ':');
        }
    }

    private function emitAddition(?\Context\AdditionContext $context): void
    {
        if ($context === null) {
            $this->builder->addText('mov x0, #0');
            return;
        }

        $parts = $this->normalizeList($context->multiplication(null));
        $currentType = $this->typeOfMultiplication($parts[0] ?? null);
        $this->emitMultiplication($parts[0] ?? null);

        for ($index = 1; $index < count($parts); $index++) {
            $operator = $context->getChild(($index * 2) - 1)?->getText() ?? '+';
            $rightType = $this->typeOfMultiplication($parts[$index]);
            if ($currentType->name === Type::FLOAT32) {
                $this->builder->addText('fmov s1, s0');
            } else {
                $this->builder->addText('mov x9, x0');
            }
            $this->emitMultiplication($parts[$index]);

            if ($operator === '+' && $currentType->name === Type::STRING && $rightType->name === Type::STRING) {
                $this->builder->addText('mov x1, x0');
                $this->builder->addText('mov x0, x9');
                $this->builder->addText('bl __concat_strings');
                $currentType = Type::string();
                continue;
            }

            $resultType = TypeRules::arithmeticResult($operator, $currentType, $rightType);
            if ($resultType?->name === Type::FLOAT32) {
                if ($currentType->name !== Type::FLOAT32) {
                    $this->builder->addText('scvtf s1, w9');
                }
                if ($rightType->name !== Type::FLOAT32) {
                    $this->builder->addText('scvtf s0, w0');
                }
                $instruction = $operator === '-' ? 'fsub' : 'fadd';
                $this->builder->addText("{$instruction} s0, s1, s0");
                $currentType = Type::float32();
                continue;
            }

            $instruction = $operator === '-' ? 'sub' : 'add';
            $this->builder->addText("{$instruction} x0, x9, x0");
            $currentType = $resultType ?? Type::int32();
        }
    }

    private function emitMultiplication(?\Context\MultiplicationContext $context): void
    {
        if ($context === null) {
            $this->builder->addText('mov x0, #0');
            return;
        }

        $parts = $this->normalizeList($context->unary(null));
        $currentType = $this->typeOfUnary($parts[0] ?? null);
        $this->emitUnary($parts[0] ?? null);
        for ($index = 1; $index < count($parts); $index++) {
            $operator = $context->getChild(($index * 2) - 1)?->getText() ?? '*';
            $rightType = $this->typeOfUnary($parts[$index]);
            if ($currentType->name === Type::FLOAT32) {
                $this->builder->addText('fmov s1, s0');
            } else {
                $this->builder->addText('mov x9, x0');
            }
            $this->emitUnary($parts[$index]);

            if ($operator === '%') {
                $this->emitModuloSequence('x9', 'x0');
                $currentType = Type::int32();
                continue;
            }

            $resultType = TypeRules::arithmeticResult($operator, $currentType, $rightType);
            if ($resultType?->name === Type::FLOAT32) {
                if ($currentType->name !== Type::FLOAT32) {
                    $this->builder->addText('scvtf s1, w9');
                }
                if ($rightType->name !== Type::FLOAT32) {
                    $this->builder->addText('scvtf s0, w0');
                }
                $instruction = $operator === '/' ? 'fdiv' : 'fmul';
                $this->builder->addText("{$instruction} s0, s1, s0");
                $currentType = Type::float32();
                continue;
            }

            $instruction = $operator === '/' ? 'sdiv' : 'mul';
            $this->builder->addText("{$instruction} x0, x9, x0");
            $currentType = $resultType ?? Type::int32();
        }
    }

    private function emitUnary(?\Context\UnaryContext $context): void
    {
        if ($context === null) {
            $this->builder->addText('mov x0, #0');
            return;
        }

        if ($context->primary() !== null) {
            $this->emitPrimary($context->primary());
            return;
        }

        $operator = $context->getChild(0)?->getText() ?? '';
        $this->emitUnary($context->unary());

        if ($operator === '-') {
            if ($this->typeOfUnary($context->unary())?->name === Type::FLOAT32) {
                $this->builder->addText('fneg s0, s0');
            } else {
                $this->builder->addText('neg x0, x0');
            }
        } elseif ($operator === '!') {
            $trueLabel = $this->labels->next('not_true');
            $endLabel = $this->labels->next('not_end');
            $this->builder->addText('cmp x0, #0');
            $this->builder->addText("b.eq {$trueLabel}");
            $this->builder->addText('mov x0, #0');
            $this->builder->addText("b {$endLabel}");
            $this->builder->addText($trueLabel . ':');
            $this->builder->addText('mov x0, #1');
            $this->builder->addText($endLabel . ':');
        }
    }

    private function emitPrimary(?\Context\PrimaryContext $context): void
    {
        if ($context === null) {
            $this->builder->addText('mov x0, #0');
            return;
        }

        if ($context->literal() !== null) {
            $this->emitLiteral($context->literal());
            return;
        }

        if ($context->qualifiedIdentifier() !== null) {
            $symbol = $this->resolveCurrentFunctionSymbol($context->qualifiedIdentifier()->getText());
            if ($symbol !== null) {
                $this->loadSymbolValue($symbol);
                return;
            }
        }

        if ($context->arrayAccess() !== null) {
            $symbol = $this->resolveCurrentFunctionSymbol($context->arrayAccess()->qualifiedIdentifier()->getText());
            if ($symbol !== null) {
                $this->emitArrayAccessValue($context->arrayAccess(), $symbol);
                return;
            }
        }

        if ($context->functionCall() !== null) {
            $this->emitFunctionCall($context->functionCall());
            return;
        }

        if ($context->expression() !== null) {
            $this->emitExpression($context->expression());
            return;
        }

        $this->builder->addText('mov x0, #0');
    }

    private function emitLiteral(\Context\LiteralContext $context): void
    {
        if ($context->INT_LITERAL() !== null) {
            $this->builder->addText('mov x0, #' . (int) $context->INT_LITERAL()->getText());
            return;
        }

        if ($context->FLOAT_LITERAL() !== null) {
            $label = $this->internFloatLiteral($context->FLOAT_LITERAL()->getText());
            $this->builder->addText("adrp x10, {$label}");
            $this->builder->addText("add x10, x10, :lo12:{$label}");
            $this->builder->addText('ldr s0, [x10]');
            return;
        }

        if ($context->STRING_LITERAL() !== null) {
            $label = $this->internStringLiteral($this->decodeStringLiteral($context->STRING_LITERAL()->getText()));
            $this->builder->addText("adrp x0, {$label}");
            $this->builder->addText("add x0, x0, :lo12:{$label}");
            return;
        }

        if ($context->RUNE_LITERAL() !== null) {
            $content = trim($context->RUNE_LITERAL()->getText(), "'");
            $value = ord($content[0] ?? "\0");
            $this->builder->addText('mov x0, #' . $value);
            return;
        }

        $text = $context->getText();
        $this->builder->addText(match ($text) {
            'true' => 'mov x0, #1',
            'false', 'nil' => 'mov x0, #0',
            default => 'mov x0, #0',
        });
    }

    private function emitFunctionCall(\Context\FunctionCallContext $context): void
    {
        $name = $context->qualifiedIdentifier()?->getText() ?? '';
        $arguments = $context->argumentList() !== null ? $this->normalizeList($context->argumentList()->expression(null)) : [];

        if ($name === BuiltinRegistry::PRINTLN) {
            $this->emitPrintlnCall($context);
            $this->builder->addText('mov x0, #0');
            return;
        }

        if ($name === BuiltinRegistry::LEN) {
            $this->emitLenBuiltin($arguments);
            return;
        }

        if ($name === BuiltinRegistry::NOW) {
            $this->builder->addText('adrp x0, fixed_now');
            $this->builder->addText('add x0, x0, :lo12:fixed_now');
            return;
        }

        if ($name === BuiltinRegistry::SUBSTR) {
            $this->emitSubstrBuiltin($arguments);
            return;
        }

        if ($name === BuiltinRegistry::TYPEOF) {
            $this->emitTypeOfBuiltin($arguments);
            return;
        }

        foreach ($arguments as $index => $argument) {
            if ($index > 7) {
                break;
            }
            $this->emitExpression($argument);
            $type = $this->typeOfExpression($argument);
            if ($type->name === Type::FLOAT32) {
                if ($index !== 0) {
                    $this->builder->addText('fmov s' . $index . ', s0');
                }
            } else {
                $this->builder->addText('mov x' . $index . ', x0');
            }
        }

        $this->builder->addText("bl {$name}");
    }

    private function emitPrintlnCall(\Context\FunctionCallContext $context): void
    {
        $arguments = $context->argumentList() !== null ? $this->normalizeList($context->argumentList()->expression(null)) : [];
        foreach ($arguments as $index => $argument) {
            $this->emitExpression($argument);
            $type = $this->typeOfExpression($argument);
            $this->emitPrintValueByType($type);
            if ($index < count($arguments) - 1) {
                $this->builder->addText('bl __print_space');
            }
        }
        $this->builder->addText('bl __print_newline');
    }

    private function emitPrintValueByType(Type $type): void
    {
        if ($type->name === Type::STRING) {
            $this->builder->addText('bl __print_cstr');
            return;
        }

        if ($type->name === Type::BOOL) {
            $trueLabel = $this->labels->next('print_bool_true');
            $endLabel = $this->labels->next('print_bool_end');
            $this->builder->addText('cmp x0, #0');
            $this->builder->addText("b.ne {$trueLabel}");
            $this->builder->addText('adrp x0, bool_false');
            $this->builder->addText('add x0, x0, :lo12:bool_false');
            $this->builder->addText('b ' . $endLabel);
            $this->builder->addText($trueLabel . ':');
            $this->builder->addText('adrp x0, bool_true');
            $this->builder->addText('add x0, x0, :lo12:bool_true');
            $this->builder->addText($endLabel . ':');
            $this->builder->addText('bl __print_cstr');
            return;
        }

        if ($type->name === Type::FLOAT32) {
            $this->builder->addText('bl __print_float_fixed3');
            return;
        }

        $this->builder->addText('bl __itoa');
        $this->builder->addText('bl __print_string_slice');
    }

    private function emitLenBuiltin(array $arguments): void
    {
        $argument = $arguments[0] ?? null;
        if ($argument === null) {
            $this->builder->addText('mov x0, #0');
            return;
        }

        $type = $this->typeOfExpression($argument);
        if ($type->isArray()) {
            $this->builder->addText('mov x0, #' . ($type->length ?? 0));
            return;
        }

        $this->emitExpression($argument);
        $this->builder->addText('bl __strlen');
    }

    private function emitSubstrBuiltin(array $arguments): void
    {
        $stringExpr = $arguments[0] ?? null;
        $startExpr = $arguments[1] ?? null;
        $lenExpr = $arguments[2] ?? null;

        if ($stringExpr === null || $startExpr === null || $lenExpr === null) {
            $this->builder->addText('mov x0, #0');
            return;
        }

        $this->emitExpression($stringExpr);
        $this->builder->addText('mov x9, x0');
        $this->emitExpression($startExpr);
        $this->builder->addText('mov x1, x0');
        $this->emitExpression($lenExpr);
        $this->builder->addText('mov x2, x0');
        $this->builder->addText('mov x0, x9');
        $this->builder->addText('bl __substr');
    }

    private function emitTypeOfBuiltin(array $arguments): void
    {
        $type = isset($arguments[0]) ? $this->typeOfExpression($arguments[0]) : Type::invalid();
        $label = match ($type->name) {
            Type::INT32 => 'type_int32',
            Type::BOOL => 'type_bool',
            Type::STRING => 'type_string',
            Type::FLOAT32 => 'type_float32',
            Type::RUNE => 'type_rune',
            Type::NIL => 'type_nil',
            default => 'type_nil',
        };
        $this->builder->addText("adrp x0, {$label}");
        $this->builder->addText("add x0, x0, :lo12:{$label}");
    }

    private function emitDefaultValue(Type $type): void
    {
        if ($type->name === Type::FLOAT32) {
            $this->builder->addText('mov w9, #0');
            $this->builder->addText('fmov s0, w9');
            return;
        }

        if ($type->name === Type::STRING || $type->isPointer() || $type->isArray()) {
            $this->builder->addText('mov x0, #0');
            return;
        }

        $this->builder->addText('mov x0, #0');
    }

    private function emitModuloSequence(string $leftRegister, string $rightRegister): void
    {
        $this->builder->addText("sdiv x10, {$leftRegister}, {$rightRegister}");
        $this->builder->addText("mul x11, x10, {$rightRegister}");
        $this->builder->addText("sub x0, {$leftRegister}, x11");
    }

    private function emitArrayVariableInitialization(Symbol $symbol, mixed $expression): void
    {
        $this->zeroArrayStorage($symbol);
        $arrayLiteral = $expression !== null ? $this->extractArrayLiteral($expression) : null;
        if ($arrayLiteral === null) {
            return;
        }

        $values = $this->flattenArrayLiteralValues($arrayLiteral);
        $elementType = $symbol->type->elementType ?? Type::int32();
        foreach ($values as $index => $literalValue) {
            $this->emitArrayElementWrite($symbol, $index, $literalValue, $elementType);
        }
    }

    private function zeroArrayStorage(Symbol $symbol): void
    {
        $totalBytes = max(8, $symbol->type->sizeInBytes());
        $wordCount = (int) ceil($totalBytes / 8);

        if ($symbol->storage === 'global') {
            $this->builder->addText("adrp x10, {$symbol->name}");
            $this->builder->addText("add x10, x10, :lo12:{$symbol->name}");
        } elseif ($symbol->stackOffset !== null) {
            $this->builder->addText("sub x10, x29, #{$symbol->stackOffset}");
        } else {
            return;
        }

        for ($i = 0; $i < $wordCount; $i++) {
            $offset = $i * 8;
            $this->builder->addText("str xzr, [x10, #{$offset}]");
        }
    }

    private function emitArrayElementAssignment(\Context\ArrayAccessContext $arrayAccess, Symbol $symbol, \Context\ExpressionContext $source): void
    {
        $indices = $this->normalizeList($arrayAccess->expression(null));
        if (count($indices) !== 1) {
            $this->registerLimitation('Los arreglos multidimensionales aun no tienen codegen completo; solo se soporta acceso lineal de un indice.');
            return;
        }

        $elementType = $this->typeOfPrimary($this->extractPrimary($source));
        $this->emitExpression($source);
        $this->computeArrayElementAddress($symbol, $indices[0], $symbol->type->elementType ?? $elementType);

        if (($symbol->type->elementType ?? $elementType)->name === Type::FLOAT32) {
            $this->builder->addText('str s0, [x11]');
        } else {
            $this->builder->addText('str x0, [x11]');
        }
    }

    private function emitArrayAccessValue(\Context\ArrayAccessContext $arrayAccess, Symbol $symbol): void
    {
        $indices = $this->normalizeList($arrayAccess->expression(null));
        if (count($indices) !== 1) {
            $this->registerLimitation('Los arreglos multidimensionales aun no tienen codegen completo; solo se soporta acceso lineal de un indice.');
            $this->builder->addText('mov x0, #0');
            return;
        }

        $elementType = $symbol->type->elementType ?? Type::int32();
        $this->computeArrayElementAddress($symbol, $indices[0], $elementType);
        if ($elementType->name === Type::FLOAT32) {
            $this->builder->addText('ldr s0, [x11]');
        } else {
            $this->builder->addText('ldr x0, [x11]');
        }
    }

    private function computeArrayElementAddress(Symbol $symbol, \Context\ExpressionContext $indexExpr, Type $elementType): void
    {
        if ($symbol->storage === 'global') {
            $this->builder->addText("adrp x10, {$symbol->name}");
            $this->builder->addText("add x10, x10, :lo12:{$symbol->name}");
        } elseif ($symbol->stackOffset !== null) {
            $this->builder->addText("sub x10, x29, #{$symbol->stackOffset}");
        } else {
            $this->builder->addText('mov x10, xzr');
        }

        $this->emitExpression($indexExpr);
        $elementSize = max(8, $elementType->sizeInBytes());
        $this->builder->addText("mov x12, #{$elementSize}");
        $this->builder->addText('mul x13, x0, x12');
        $this->builder->addText('add x11, x10, x13');
    }

    private function emitArrayElementWrite(Symbol $symbol, int $index, mixed $literalValue, Type $elementType): void
    {
        if ($symbol->storage === 'global') {
            $this->builder->addText("adrp x10, {$symbol->name}");
            $this->builder->addText("add x10, x10, :lo12:{$symbol->name}");
        } elseif ($symbol->stackOffset !== null) {
            $this->builder->addText("sub x10, x29, #{$symbol->stackOffset}");
        } else {
            return;
        }

        $offset = $index * max(8, $elementType->sizeInBytes());
        if ($elementType->name === Type::FLOAT32) {
            $label = $this->internFloatLiteral(is_string($literalValue) ? $literalValue : ((string) $literalValue));
            $this->builder->addText("adrp x11, {$label}");
            $this->builder->addText("add x11, x11, :lo12:{$label}");
            $this->builder->addText('ldr s0, [x11]');
            $this->builder->addText("str s0, [x10, #{$offset}]");
            return;
        }

        $value = is_bool($literalValue) ? ($literalValue ? 1 : 0) : (int) $literalValue;
        $this->builder->addText("mov x0, #{$value}");
        $this->builder->addText("str x0, [x10, #{$offset}]");
    }

    private function extractArrayLiteral(?\Context\ExpressionContext $context): ?\Context\ArrayLiteralContext
    {
        return $this->extractPrimary($context)?->arrayLiteral();
    }

    /**
     * @return list<int|float|bool>
     */
    private function flattenArrayLiteralValues(\Context\ArrayLiteralContext $arrayLiteral): array
    {
        $body = $arrayLiteral->arrayLiteralBody();
        if ($body === null || $body->arrayElementList() === null) {
            return [];
        }

        $values = [];
        foreach ($this->normalizeList($body->arrayElementList()->arrayElement(null)) as $element) {
            if ($element->expression() !== null) {
                $values[] = $this->constantScalarValue($element->expression());
            } elseif ($element->arrayLiteralBody() !== null) {
                foreach ($this->normalizeList($element->arrayLiteralBody()->arrayElementList()?->arrayElement(null)) as $nested) {
                    if ($nested->expression() !== null) {
                        $values[] = $this->constantScalarValue($nested->expression());
                    }
                }
            }
        }

        return $values;
    }

    private function constantScalarValue(\Context\ExpressionContext $expression): int|float|bool
    {
        $primary = $this->extractPrimary($expression);
        $literal = $primary?->literal();
        if ($literal?->FLOAT_LITERAL() !== null) {
            return (float) $literal->FLOAT_LITERAL()->getText();
        }
        if ($literal?->INT_LITERAL() !== null) {
            return (int) $literal->INT_LITERAL()->getText();
        }
        if ($literal?->getText() === 'true') {
            return true;
        }
        if ($literal?->getText() === 'false') {
            return false;
        }
        if ($literal?->RUNE_LITERAL() !== null) {
            $content = trim($literal->RUNE_LITERAL()->getText(), "'");
            return ord($content[0] ?? "\0");
        }
        return 0;
    }

    private function isPointerAssignment(\Context\ExpressionContext $expression): bool
    {
        $unary = $expression->logicalOr()?->logicalAnd(0)?->equality(0)?->comparison(0)?->addition(0)?->multiplication(0)?->unary(0);
        return $unary !== null && $unary->primary() === null && $unary->getChild(0)?->getText() === '*';
    }

    private function extractArrayAccess(\Context\ExpressionContext $expression): ?\Context\ArrayAccessContext
    {
        return $this->extractPrimary($expression)?->arrayAccess();
    }

    private function loadSymbolToX0(Symbol $symbol): void
    {
        if ($symbol->type->name === Type::FLOAT32) {
            $this->loadSymbolValue($symbol);
            return;
        }

        if ($symbol->storage === 'global') {
            if ($symbol->type->name === Type::STRING) {
                $this->builder->addText("adrp x10, {$symbol->name}");
                $this->builder->addText("add x10, x10, :lo12:{$symbol->name}");
                $this->builder->addText('ldr x0, [x10]');
                return;
            }

            $this->builder->addText("adrp x10, {$symbol->name}");
            $this->builder->addText("add x10, x10, :lo12:{$symbol->name}");
            $this->builder->addText('ldr x0, [x10]');
            return;
        }

        if ($symbol->stackOffset !== null) {
            $this->builder->addText("ldr x0, [x29, #-{$symbol->stackOffset}]");
            return;
        }

        $this->builder->addText('mov x0, #0');
    }

    private function loadSymbolValue(Symbol $symbol): void
    {
        if ($symbol->type->name !== Type::FLOAT32) {
            $this->loadSymbolToX0($symbol);
            return;
        }

        if ($symbol->storage === 'global') {
            $this->builder->addText("adrp x10, {$symbol->name}");
            $this->builder->addText("add x10, x10, :lo12:{$symbol->name}");
            $this->builder->addText('ldr s0, [x10]');
            return;
        }

        if ($symbol->stackOffset !== null) {
            $this->builder->addText("ldr s0, [x29, #-{$symbol->stackOffset}]");
            return;
        }

        $this->builder->addText('mov w9, #0');
        $this->builder->addText('fmov s0, w9');
    }

    private function storeExpressionResult(Symbol $symbol): void
    {
        if ($symbol->type->name === Type::FLOAT32) {
            if ($symbol->storage === 'global') {
                $this->builder->addText("adrp x10, {$symbol->name}");
                $this->builder->addText("add x10, x10, :lo12:{$symbol->name}");
                $this->builder->addText('str s0, [x10]');
                return;
            }

            if ($symbol->stackOffset !== null) {
                $this->builder->addText("str s0, [x29, #-{$symbol->stackOffset}]");
            }
            return;
        }

        if ($symbol->storage === 'global') {
            $this->builder->addText("adrp x10, {$symbol->name}");
            $this->builder->addText("add x10, x10, :lo12:{$symbol->name}");
            $this->builder->addText('str x0, [x10]');
            return;
        }

        if ($symbol->stackOffset !== null) {
            $this->builder->addText("str x0, [x29, #-{$symbol->stackOffset}]");
        }
    }

    private function internStringLiteral(string $value): string
    {
        if (isset($this->stringLiterals[$value])) {
            return $this->stringLiterals[$value];
        }

        $label = $this->labels->next('str_lit');
        $escaped = addcslashes($value, "\\\"\n\r\t");
        $escaped = str_replace("\n", '\n', $escaped);
        $escaped = str_replace("\r", '\r', $escaped);
        $escaped = str_replace("\t", '\t', $escaped);
        $this->builder->addRodata($label . ': .asciz "' . $escaped . '"');
        $this->stringLiterals[$value] = $label;
        return $label;
    }

    private function internFloatLiteral(string $value): string
    {
        if (isset($this->floatLiterals[$value])) {
            return $this->floatLiterals[$value];
        }

        $label = $this->labels->next('float_lit');
        $this->builder->addRodata($label . ': .float ' . $value);
        $this->floatLiterals[$value] = $label;
        return $label;
    }

    private function decodeStringLiteral(string $literal): string
    {
        return stripcslashes(substr($literal, 1, -1));
    }

    private function literalLabelFromExpression(mixed $expression): ?string
    {
        $primary = $this->extractPrimary($expression);
        if ($primary?->literal()?->STRING_LITERAL() === null) {
            return null;
        }

        return $this->internStringLiteral(
            $this->decodeStringLiteral($primary->literal()->STRING_LITERAL()->getText())
        );
    }

    private function constantIntFromExpression(mixed $expression): ?int
    {
        $primary = $this->extractPrimary($expression);
        if ($primary?->literal()?->INT_LITERAL() !== null) {
            return (int) $primary->literal()->INT_LITERAL()->getText();
        }
        if ($primary?->literal()?->RUNE_LITERAL() !== null) {
            $content = trim($primary->literal()->RUNE_LITERAL()->getText(), "'");
            return ord($content[0] ?? "\0");
        }
        return null;
    }

    private function constantFloatFromExpression(mixed $expression): ?string
    {
        $primary = $this->extractPrimary($expression);
        if ($primary?->literal()?->FLOAT_LITERAL() !== null) {
            return $primary->literal()->FLOAT_LITERAL()->getText();
        }
        if ($primary?->literal()?->INT_LITERAL() !== null) {
            return $primary->literal()->INT_LITERAL()->getText() . '.0';
        }
        return null;
    }

    private function constantBoolFromExpression(mixed $expression): bool
    {
        $primary = $this->extractPrimary($expression);
        $text = $primary?->literal()?->getText();
        return $text === 'true';
    }

    private function extractPrimary(?\Context\ExpressionContext $context): ?\Context\PrimaryContext
    {
        return $context?->logicalOr()?->logicalAnd(0)?->equality(0)?->comparison(0)?->addition(0)?->multiplication(0)?->unary(0)?->primary();
    }

    private function extractFunctionCall(?\Context\ExpressionContext $context): ?\Context\FunctionCallContext
    {
        return $this->extractPrimary($context)?->functionCall();
    }

    private function typeOfExpression(?\Context\ExpressionContext $context): Type
    {
        return $this->typeOfLogicalOr($context?->logicalOr());
    }

    private function typeOfLogicalOr(?\Context\LogicalOrContext $context): Type
    {
        if ($context === null) {
            return Type::invalid();
        }

        $parts = $this->normalizeList($context->logicalAnd(null));
        if (count($parts) > 1) {
            return Type::bool();
        }
        return $this->typeOfLogicalAnd($parts[0] ?? null);
    }

    private function typeOfLogicalAnd(?\Context\LogicalAndContext $context): Type
    {
        if ($context === null) {
            return Type::invalid();
        }

        $parts = $this->normalizeList($context->equality(null));
        if (count($parts) > 1) {
            return Type::bool();
        }
        return $this->typeOfEquality($parts[0] ?? null);
    }

    private function typeOfEquality(?\Context\EqualityContext $context): Type
    {
        if ($context === null) {
            return Type::invalid();
        }

        $parts = $this->normalizeList($context->comparison(null));
        if (count($parts) > 1) {
            return Type::bool();
        }
        return $this->typeOfComparison($parts[0] ?? null);
    }

    private function typeOfComparison(?\Context\ComparisonContext $context): Type
    {
        if ($context === null) {
            return Type::invalid();
        }

        $parts = $this->normalizeList($context->addition(null));
        if (count($parts) > 1) {
            return Type::bool();
        }
        return $this->typeOfAddition($parts[0] ?? null);
    }

    private function typeOfAddition(?\Context\AdditionContext $context): Type
    {
        if ($context === null) {
            return Type::invalid();
        }

        $parts = $this->normalizeList($context->multiplication(null));
        $type = $this->typeOfMultiplication($parts[0] ?? null);
        for ($index = 1; $index < count($parts); $index++) {
            $operator = $context->getChild(($index * 2) - 1)?->getText() ?? '+';
            $rightType = $this->typeOfMultiplication($parts[$index]);
            $type = TypeRules::arithmeticResult($operator, $type, $rightType) ?? Type::invalid();
        }
        return $type;
    }

    private function typeOfMultiplication(?\Context\MultiplicationContext $context): Type
    {
        if ($context === null) {
            return Type::invalid();
        }

        $parts = $this->normalizeList($context->unary(null));
        $type = $this->typeOfUnary($parts[0] ?? null);
        for ($index = 1; $index < count($parts); $index++) {
            $operator = $context->getChild(($index * 2) - 1)?->getText() ?? '*';
            $rightType = $this->typeOfUnary($parts[$index]);
            $type = TypeRules::arithmeticResult($operator, $type, $rightType) ?? Type::invalid();
        }
        return $type;
    }

    private function typeOfUnary(?\Context\UnaryContext $context): Type
    {
        if ($context === null) {
            return Type::invalid();
        }

        if ($context->primary() !== null) {
            return $this->typeOfPrimary($context->primary());
        }

        $operator = $context->getChild(0)?->getText() ?? '';
        $operand = $this->typeOfUnary($context->unary());
        return match ($operator) {
            '!' => TypeRules::unaryNotResult($operand) ?? Type::invalid(),
            '-' => TypeRules::unaryMinusResult($operand) ?? Type::invalid(),
            '&' => Type::pointerTo($operand),
            '*' => $operand->pointedType ?? Type::invalid(),
            default => Type::invalid(),
        };
    }

    private function typeOfPrimary(?\Context\PrimaryContext $context): Type
    {
        if ($context === null) {
            return Type::invalid();
        }

        if ($context->literal() !== null) {
            return $this->typeOfLiteral($context->literal());
        }

        if ($context->qualifiedIdentifier() !== null) {
            return $this->resolveCurrentFunctionSymbol($context->qualifiedIdentifier()->getText())?->type ?? Type::invalid();
        }

        if ($context->arrayAccess() !== null) {
            $symbolType = $this->resolveCurrentFunctionSymbol($context->arrayAccess()->qualifiedIdentifier()->getText())?->type;
            if ($symbolType === null) {
                return Type::invalid();
            }
            foreach ($this->normalizeList($context->arrayAccess()->expression(null)) as $unused) {
                if ($symbolType->isArray() && $symbolType->elementType !== null) {
                    $symbolType = $symbolType->elementType;
                }
            }
            return $symbolType;
        }

        if ($context->functionCall() !== null) {
            $name = $context->functionCall()->qualifiedIdentifier()?->getText() ?? '';
            if (BuiltinRegistry::isBuiltin($name)) {
                return BuiltinRegistry::returnTypes($name)[0] ?? Type::invalid();
            }
            return $this->model->function($name)?->type ?? Type::invalid();
        }

        if ($context->expression() !== null) {
            return $this->typeOfExpression($context->expression());
        }

        return Type::invalid();
    }

    private function typeOfLiteral(\Context\LiteralContext $context): Type
    {
        if ($context->INT_LITERAL() !== null) {
            return Type::int32();
        }
        if ($context->FLOAT_LITERAL() !== null) {
            return Type::float32();
        }
        if ($context->STRING_LITERAL() !== null) {
            return Type::string();
        }
        if ($context->RUNE_LITERAL() !== null) {
            return Type::rune();
        }

        return match ($context->getText()) {
            'true', 'false' => Type::bool(),
            'nil' => Type::nil(),
            default => Type::invalid(),
        };
    }

    private function typeOfSymbol(Symbol $symbol): Type
    {
        return $symbol->type;
    }

    private function registerLimitation(string $message): void
    {
        if (!in_array($message, $this->limitations, true)) {
            $this->limitations[] = $message;
        }
    }

    private function resolveCurrentFunctionSymbol(string $name): ?Symbol
    {
        foreach ($this->model->symbolTable->getDeclaredSymbols() as $symbol) {
            if ($symbol->name === $name && $symbol->ownerFunction === $this->currentFunction?->name) {
                return $symbol;
            }
            if ($symbol->name === $name && $symbol->storage === 'global') {
                return $symbol;
            }
        }

            return $this->currentFunction !== null
                ? null
                : $this->model->symbolTable->resolve($name);
    }

    private function symbolFromExpression(\Context\ExpressionContext $context): ?Symbol
    {
        $primary = $this->extractPrimary($context);
        if ($primary?->qualifiedIdentifier() !== null) {
            return $this->resolveCurrentFunctionSymbol($primary->qualifiedIdentifier()->getText());
        }
        if ($primary?->arrayAccess() !== null) {
            return $this->resolveCurrentFunctionSymbol($primary->arrayAccess()->qualifiedIdentifier()->getText());
        }
        return null;
    }

    /**
     * @return list<object>
     */
    private function normalizeList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (is_array($value)) {
            return array_values(array_filter($value));
        }
        return [$value];
    }
}
