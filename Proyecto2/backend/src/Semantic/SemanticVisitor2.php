<?php

declare(strict_types=1);

namespace Proyecto2\Semantic;

use Proyecto2\Diagnostics\DiagnosticBag;

final class SemanticVisitor2 extends \GolampiBaseVisitor
{
    private SymbolTable $symbolTable;
    private DiagnosticBag $diagnostics;

    /** @var array<string, FunctionSymbol> */
    private array $functions = [];

    private int $mainCount = 0;
    private int $loopDepth = 0;
    private int $switchDepth = 0;

    private ?FunctionSymbol $currentFunction = null;
    private bool $currentFunctionHasReturn = false;
    private int $currentStackOffset = 0;

    public function __construct(?DiagnosticBag $diagnostics = null)
    {
        $this->symbolTable = new SymbolTable();
        $this->diagnostics = $diagnostics ?? new DiagnosticBag();
    }

    public function diagnostics(): DiagnosticBag
    {
        return $this->diagnostics;
    }

    public function model(): SemanticModel
    {
        return new SemanticModel($this->symbolTable, $this->functions);
    }

    public function visitProgram(\Context\ProgramContext $context): mixed
    {
        $decls = $this->normalizeList($context->topLevelDecl(null));

        foreach ($decls as $decl) {
            $function = $decl->functionDecl();
            if ($function !== null) {
                $this->registerFunctionSignature($function);
            }
        }

        if ($this->mainCount === 0) {
            $this->addError('Semántico', $context, 'El programa debe definir exactamente una función main.');
        }

        if ($this->mainCount > 1) {
            $this->addError('Semántico', $context, 'Solo puede existir una función main.');
        }

        foreach ($decls as $decl) {
            if ($decl->varDecl() !== null) {
                $this->visitVarDecl($decl->varDecl());
            } elseif ($decl->constDecl() !== null) {
                $this->visitConstDecl($decl->constDecl());
            } elseif ($decl->functionDecl() !== null) {
                $this->visitFunctionBody($decl->functionDecl());
            }
        }

        return $this->model();
    }

    public function visitBlock(\Context\BlockContext $context): mixed
    {
        $this->symbolTable->pushScope('block', 'block');
        foreach ($this->normalizeList($context->statement(null)) as $statement) {
            $this->visit($statement);
        }
        $this->symbolTable->popScope();
        return null;
    }

    public function visitVarDecl(\Context\VarDeclContext $context): mixed
    {
        $type = $context->type() !== null ? $this->resolveType($context->type()) : Type::int32();
        $identifiers = $this->collectIdentifiers($context->identifierList());
        $rhsTypes = $context->expressionList() !== null ? $this->expandExpressionTypes($context->expressionList()) : [];
        $rhsValues = $context->expressionList() !== null ? $this->expandConstantValues($context->expressionList()) : [];

        if ($rhsTypes !== [] && count($identifiers) !== count($rhsTypes)) {
            $this->addError('Semántico', $context, 'La cantidad de variables y expresiones no coincide.');
        }

        foreach ($identifiers as $index => $identifier) {
            $name = $identifier->getText();
            if ($this->symbolTable->isDefinedInCurrentScope($name)) {
                $this->addError('Semántico', $context, "Identificador '{$name}' ya declarado en este ámbito.");
                continue;
            }

            $sourceType = $rhsTypes[$index] ?? null;
            if ($sourceType !== null && !TypeRules::assignmentAllowed($type, $sourceType)) {
                $this->addError(
                    'Semántico',
                    $context,
                    "No se puede asignar '{$sourceType}' a variable '{$name}' de tipo '{$type}'."
                );
            }

            $symbol = new Symbol(
                $name,
                $type,
                'variable',
                $this->symbolTable->currentScope()->name,
                $this->symbolTable->currentScope()->level,
                $this->tokenLine($identifier),
                $this->tokenColumn($identifier),
                $rhsValues[$index] ?? null,
                $this->allocateOffsetIfNeeded($type),
                $this->currentFunction === null ? 'global' : 'stack',
                $this->currentFunction?->name,
                true
            );

            $this->safeDefine($symbol, $context);
        }

        return null;
    }

    public function visitConstDecl(\Context\ConstDeclContext $context): mixed
    {
        $identifier = $context->IDENTIFIER();
        $name = $identifier !== null ? $identifier->getText() : '';
        $type = $context->type() !== null ? $this->resolveType($context->type()) : Type::invalid();
        $expr = $context->expression();
        $exprType = $expr !== null ? $this->inferExpressionType($expr) : Type::invalid();

        if ($name !== '' && $this->symbolTable->isDefinedInCurrentScope($name)) {
            $this->addError('Semántico', $context, "Identificador '{$name}' ya declarado en este ámbito.");
            return null;
        }

        if (!TypeRules::assignmentAllowed($type, $exprType)) {
            $this->addError(
                'Semántico',
                $context,
                "La constante '{$name}' de tipo '{$type}' no puede inicializarse con '{$exprType}'."
            );
        }

        $symbol = new Symbol(
            $name,
            $type,
            'constant',
            $this->symbolTable->currentScope()->name,
            $this->symbolTable->currentScope()->level,
            $this->tokenLine($identifier),
            $this->tokenColumn($identifier),
            $expr !== null ? $this->constantValueOfExpression($expr) : null,
            $this->allocateOffsetIfNeeded($type),
            $this->currentFunction === null ? 'global' : 'stack',
            $this->currentFunction?->name,
            false
        );

        $this->safeDefine($symbol, $context);
        return null;
    }

    public function visitShortVarDecl(\Context\ShortVarDeclContext $context): mixed
    {
        if ($this->currentFunction === null) {
            $this->addError('Semántico', $context, 'La declaración corta solo puede usarse dentro de funciones.');
            return null;
        }

        $identifiers = $this->collectIdentifiers($context->identifierList());
        $rhsTypes = $this->expandExpressionTypes($context->expressionList());
        $rhsValues = $this->expandConstantValues($context->expressionList());

        if (count($identifiers) !== count($rhsTypes)) {
            $this->addError('Semántico', $context, 'La cantidad de variables y expresiones no coincide.');
            return null;
        }

        $declaredAny = false;
        foreach ($identifiers as $index => $identifier) {
            $name = $identifier->getText();
            $rhsType = $rhsTypes[$index];
            $existing = $this->symbolTable->currentScope()->resolveLocal($name);

            if ($existing !== null) {
                if (!$existing->mutable) {
                    $this->addError('Semántico', $context, "No se puede reasignar la constante '{$name}'.");
                    continue;
                }
                if (!TypeRules::assignmentAllowed($existing->type, $rhsType)) {
                    $this->addError(
                        'Semántico',
                        $context,
                        "La variable '{$name}' no acepta una asignación de tipo '{$rhsType}'."
                    );
                }
                continue;
            }

            $declaredAny = true;
            $symbol = new Symbol(
                $name,
                $rhsType,
                'variable',
                $this->symbolTable->currentScope()->name,
                $this->symbolTable->currentScope()->level,
                $this->tokenLine($identifier),
                $this->tokenColumn($identifier),
                $rhsValues[$index] ?? null,
                $this->allocateOffsetIfNeeded($rhsType),
                'stack',
                $this->currentFunction?->name,
                true
            );

            $this->safeDefine($symbol, $context);
        }

        if (!$declaredAny) {
            $this->addError('Semántico', $context, 'La declaración corta requiere al menos una variable nueva.');
        }

        return null;
    }

    public function visitAssignment(\Context\AssignmentContext $context): mixed
    {
        $left = $context->expression(0);
        $right = $context->expression(1);
        $operator = $context->assignOp()?->getText() ?? '=';

        if ($left === null || $right === null) {
            return null;
        }

        if (!$this->isAssignableExpression($left)) {
            $this->addError('Semántico', $context, 'El lado izquierdo de la asignación no es válido.');
            return null;
        }

        $targetSymbol = $this->symbolFromExpression($left);
        if ($targetSymbol !== null && !$targetSymbol->mutable) {
            $this->addError('Semántico', $context, "No se puede modificar la constante '{$targetSymbol->name}'.");
        }

        $leftType = $this->inferExpressionType($left);
        $rightType = $this->inferExpressionType($right);

        if ($operator === '=') {
            if (!TypeRules::assignmentAllowed($leftType, $rightType)) {
                $this->addError(
                    'Semántico',
                    $context,
                    "Asignación inválida: '{$rightType}' no es compatible con '{$leftType}'."
                );
            }
            return null;
        }

        $baseOperator = match ($operator) {
            '+=' => '+',
            '-=' => '-',
            '*=' => '*',
            '/=' => '/',
            default => null,
        };

        $resultType = $baseOperator !== null ? TypeRules::arithmeticResult($baseOperator, $leftType, $rightType) : null;
        if ($resultType === null || !TypeRules::assignmentAllowed($leftType, $resultType)) {
            $this->addError(
                'Semántico',
                $context,
                "Asignación compuesta inválida entre '{$leftType}' y '{$rightType}'."
            );
        }

        return null;
    }

    public function visitIncDecStmt(\Context\IncDecStmtContext $context): mixed
    {
        $expr = $context->expression();
        if ($expr === null) {
            return null;
        }

        if (!$this->isAssignableExpression($expr)) {
            $this->addError('Semántico', $context, 'La operación requiere una variable asignable.');
            return null;
        }

        $type = $this->inferExpressionType($expr);
        if (!$type->isNumeric()) {
            $this->addError('Semántico', $context, 'Solo se puede aplicar ++/-- a valores numéricos.');
        }

        return null;
    }

    public function visitIfStmt(\Context\IfStmtContext $context): mixed
    {
        $this->symbolTable->pushScope('if', 'block');

        $simpleStmt = $context->simpleStmt();
        if ($simpleStmt !== null) {
            $this->handleSimpleStmt($simpleStmt);
        }

        $condition = $context->expression();
        if ($condition !== null) {
            $conditionType = $this->inferExpressionType($condition);
            if ($conditionType->name !== Type::BOOL) {
                $this->addError('Semántico', $context, 'La condición de if debe ser booleana.');
            }
        }

        $blocks = $this->normalizeList($context->block(null));
        if (isset($blocks[0])) {
            $this->visitBlock($blocks[0]);
        }

        if (isset($blocks[1])) {
            $this->visitBlock($blocks[1]);
        }

        $elseIf = $context->ifStmt();
        if ($elseIf !== null) {
            $this->visitIfStmt($elseIf);
        }

        $this->symbolTable->popScope();
        return null;
    }

    public function visitForStmt(\Context\ForStmtContext $context): mixed
    {
        $this->loopDepth++;
        $this->symbolTable->pushScope('for', 'loop');

        if ($context->forClause() !== null) {
            $forClause = $context->forClause();
            $simpleStmts = $this->normalizeList($forClause->simpleStmt(null));
            if (isset($simpleStmts[0])) {
                $this->handleSimpleStmt($simpleStmts[0]);
            }

            $condition = $forClause->expression();
            if ($condition !== null) {
                $conditionType = $this->inferExpressionType($condition);
                if ($conditionType->name !== Type::BOOL) {
                    $this->addError('Semántico', $context, 'La condición del for debe ser booleana.');
                }
            }

            if (isset($simpleStmts[1])) {
                $this->handleSimpleStmt($simpleStmts[1]);
            }
        } elseif ($context->expression() !== null) {
            $conditionType = $this->inferExpressionType($context->expression());
            if ($conditionType->name !== Type::BOOL) {
                $this->addError('Semántico', $context, 'La condición del for debe ser booleana.');
            }
        }

        if ($context->block() !== null) {
            $this->visitBlock($context->block());
        }

        $this->symbolTable->popScope();
        $this->loopDepth--;
        return null;
    }

    public function visitSwitchStmt(\Context\SwitchStmtContext $context): mixed
    {
        $this->switchDepth++;
        $switchType = $context->expression() !== null ? $this->inferExpressionType($context->expression()) : Type::invalid();

        foreach ($this->normalizeList($context->caseClause(null)) as $caseClause) {
            $types = $caseClause->expressionList() !== null
                ? $this->expandExpressionTypes($caseClause->expressionList())
                : [];

            foreach ($types as $caseType) {
                if (TypeRules::equalityResult($switchType, $caseType) === null) {
                    $this->addError(
                        'Semántico',
                        $caseClause,
                        "El case '{$caseType}' no es compatible con la expresión de switch '{$switchType}'."
                    );
                }
            }

            $this->symbolTable->pushScope('case', 'block');
            foreach ($this->normalizeList($caseClause->statement(null)) as $statement) {
                $this->visit($statement);
            }
            $this->symbolTable->popScope();
        }

        if ($context->defaultClause() !== null) {
            $this->symbolTable->pushScope('default', 'block');
            foreach ($this->normalizeList($context->defaultClause()->statement(null)) as $statement) {
                $this->visit($statement);
            }
            $this->symbolTable->popScope();
        }

        $this->switchDepth--;
        return null;
    }

    public function visitBreakStmt(\Context\BreakStmtContext $context): mixed
    {
        if ($this->loopDepth === 0 && $this->switchDepth === 0) {
            $this->addError('Semántico', $context, 'break solo puede usarse dentro de for o switch.');
        }

        return null;
    }

    public function visitContinueStmt(\Context\ContinueStmtContext $context): mixed
    {
        if ($this->loopDepth === 0) {
            $this->addError('Semántico', $context, 'continue solo puede usarse dentro de for.');
        }

        return null;
    }

    public function visitReturnStmt(\Context\ReturnStmtContext $context): mixed
    {
        if ($this->currentFunction === null) {
            $this->addError('Semántico', $context, 'return solo puede usarse dentro de una función.');
            return null;
        }

        $returnTypes = $context->expressionList() !== null ? $this->expandExpressionTypes($context->expressionList()) : [];
        $expected = $this->currentFunction->returnTypes;

        if ($this->currentFunction->name === 'main' && $returnTypes !== []) {
            $this->addError('Semántico', $context, 'La función main no puede retornar valores.');
            return null;
        }

        if (count($returnTypes) !== count($expected)) {
            $this->addError(
                'Semántico',
                $context,
                "La función '{$this->currentFunction->name}' debe retornar " . count($expected) . ' valor(es).'
            );
            return null;
        }

        foreach ($expected as $index => $type) {
            if (!isset($returnTypes[$index])) {
                continue;
            }

            if (!TypeRules::assignmentAllowed($type, $returnTypes[$index])) {
                $this->addError(
                    'Semántico',
                    $context,
                    "El retorno {$index} no coincide: se esperaba '{$type}' y se obtuvo '{$returnTypes[$index]}'."
                );
            }
        }

        $this->currentFunctionHasReturn = true;
        return null;
    }

    public function visitExpressionStmt(\Context\ExpressionStmtContext $context): mixed
    {
        if ($context->expression() !== null) {
            $this->inferExpressionType($context->expression());
        }
        return null;
    }

    private function registerFunctionSignature(\Context\FunctionDeclContext $context): void
    {
        $identifier = $context->IDENTIFIER();
        $name = $identifier !== null ? $identifier->getText() : '';

        if ($name === 'main') {
            $this->mainCount++;
            if ($context->parameters() !== null && $context->parameters()->parameter(0) !== null) {
                $this->addError('Semántico', $context, 'La función main no puede tener parámetros.');
            }
            if ($context->returnType() !== null) {
                $this->addError('Semántico', $context, 'La función main no puede tener tipo de retorno.');
            }
        }

        if ($this->symbolTable->isDefinedInCurrentScope($name)) {
            $this->addError('Semántico', $context, "La función '{$name}' ya fue declarada.");
            return;
        }

        $parameterTypes = [];
        $parameterNames = [];
        if ($context->parameters() !== null) {
            foreach ($this->normalizeList($context->parameters()->parameter(null)) as $parameter) {
                $parameterNames[] = $parameter->IDENTIFIER()?->getText() ?? '';
                $parameterTypes[] = $parameter->type() !== null ? $this->resolveType($parameter->type()) : Type::invalid();
            }
        }

        $returnTypes = [];
        if ($context->returnType() !== null) {
            foreach ($this->normalizeList($context->returnType()->type(null)) as $typeContext) {
                $returnTypes[] = $this->resolveType($typeContext);
            }
        }

        $function = new FunctionSymbol(
            $name,
            $returnTypes[0] ?? Type::nil(),
            'global',
            0,
            $this->tokenLine($identifier),
            $this->tokenColumn($identifier),
            $parameterTypes,
            $parameterNames,
            $returnTypes,
            $context
        );

        $this->functions[$name] = $function;
        $this->safeDefine($function, $context);
    }

    private function visitFunctionBody(\Context\FunctionDeclContext $context): void
    {
        $name = $context->IDENTIFIER()?->getText() ?? '';
        $function = $this->functions[$name] ?? null;
        if ($function === null) {
            return;
        }

        $previousFunction = $this->currentFunction;
        $previousHasReturn = $this->currentFunctionHasReturn;
        $previousOffset = $this->currentStackOffset;

        $this->currentFunction = $function;
        $this->currentFunctionHasReturn = false;
        $this->currentStackOffset = 0;

        $this->symbolTable->pushScope($name, 'function');

        if ($context->parameters() !== null) {
            foreach ($this->normalizeList($context->parameters()->parameter(null)) as $index => $parameter) {
                $parameterName = $parameter->IDENTIFIER()?->getText() ?? '';
                $parameterType = $parameter->type() !== null ? $this->resolveType($parameter->type()) : Type::invalid();
                $symbol = new Symbol(
                    $parameterName,
                    $parameterType,
                    'parameter',
                    $this->symbolTable->currentScope()->name,
                    $this->symbolTable->currentScope()->level,
                    $this->tokenLine($parameter->IDENTIFIER()),
                    $this->tokenColumn($parameter->IDENTIFIER()),
                    null,
                    $this->allocateOffsetIfNeeded($parameterType),
                    'stack',
                    $name,
                    true
                );
                $this->safeDefine($symbol, $parameter);
            }
        }

        $block = $context->block();
        if ($block !== null) {
            foreach ($this->normalizeList($block->statement(null)) as $statement) {
                $this->visit($statement);
            }
        }

        if ($function->returnTypes !== [] && !$this->currentFunctionHasReturn && $name !== 'main') {
            $this->addError(
                'Semántico',
                $context,
                "La función '{$name}' debe retornar " . count($function->returnTypes) . ' valor(es).'
            );
        }

        $function->frameSize = $this->align16($this->currentStackOffset);
        $this->functions[$name] = $function;

        $this->symbolTable->popScope();
        $this->currentFunction = $previousFunction;
        $this->currentFunctionHasReturn = $previousHasReturn;
        $this->currentStackOffset = $previousOffset;
    }

    private function handleSimpleStmt(\Context\SimpleStmtContext $context): void
    {
        if ($context->shortVarDecl() !== null) {
            $this->visitShortVarDecl($context->shortVarDecl());
            return;
        }
        if ($context->varDecl() !== null) {
            $this->visitVarDecl($context->varDecl());
            return;
        }
        if ($context->assignment() !== null) {
            $this->visitAssignment($context->assignment());
            return;
        }
        if ($context->incDecStmt() !== null) {
            $this->visitIncDecStmt($context->incDecStmt());
            return;
        }
        if ($context->expressionStmt() !== null) {
            $this->visitExpressionStmt($context->expressionStmt());
        }
    }

    private function inferExpressionType(?\Context\ExpressionContext $context): Type
    {
        if ($context === null) {
            return Type::invalid();
        }

        return $this->inferLogicalOr($context->logicalOr());
    }

    private function inferLogicalOr(?\Context\LogicalOrContext $context): Type
    {
        if ($context === null) {
            return Type::invalid();
        }

        $type = $this->inferLogicalAnd($context->logicalAnd(0));
        for ($i = 1; $i < $context->getChildCount(); $i += 2) {
            $right = $this->inferLogicalAnd($context->logicalAnd((int) (($i + 1) / 2)));
            $type = TypeRules::logicalResult($type, $right) ?? Type::invalid();
            if ($type->isInvalid()) {
                $this->addError('Semántico', $context, 'El operador || requiere operandos bool.');
                return $type;
            }
        }
        return $type;
    }

    private function inferLogicalAnd(?\Context\LogicalAndContext $context): Type
    {
        if ($context === null) {
            return Type::invalid();
        }

        $type = $this->inferEquality($context->equality(0));
        for ($i = 1; $i < $context->getChildCount(); $i += 2) {
            $right = $this->inferEquality($context->equality((int) (($i + 1) / 2)));
            $type = TypeRules::logicalResult($type, $right) ?? Type::invalid();
            if ($type->isInvalid()) {
                $this->addError('Semántico', $context, 'El operador && requiere operandos bool.');
                return $type;
            }
        }
        return $type;
    }

    private function inferEquality(?\Context\EqualityContext $context): Type
    {
        if ($context === null) {
            return Type::invalid();
        }

        $type = $this->inferComparison($context->comparison(0));
        for ($i = 1; $i < $context->getChildCount(); $i += 2) {
            $right = $this->inferComparison($context->comparison((int) (($i + 1) / 2)));
            $result = TypeRules::equalityResult($type, $right);
            if ($result === null) {
                $this->addError('Semántico', $context, "Comparación inválida entre '{$type}' y '{$right}'.");
                return Type::invalid();
            }
            $type = $result;
        }
        return $type;
    }

    private function inferComparison(?\Context\ComparisonContext $context): Type
    {
        if ($context === null) {
            return Type::invalid();
        }

        $type = $this->inferAddition($context->addition(0));
        for ($i = 1; $i < $context->getChildCount(); $i += 2) {
            $right = $this->inferAddition($context->addition((int) (($i + 1) / 2)));
            $result = TypeRules::relationalResult($type, $right);
            if ($result === null) {
                $this->addError('Semántico', $context, "Operación relacional inválida entre '{$type}' y '{$right}'.");
                return Type::invalid();
            }
            $type = $result;
        }
        return $type;
    }

    private function inferAddition(?\Context\AdditionContext $context): Type
    {
        if ($context === null) {
            return Type::invalid();
        }

        $type = $this->inferMultiplication($context->multiplication(0));
        $multCount = count($this->normalizeList($context->multiplication(null)));
        for ($i = 1; $i < $multCount; $i++) {
            $operator = $context->getChild(($i * 2) - 1)?->getText() ?? '+';
            $right = $this->inferMultiplication($context->multiplication($i));
            $result = TypeRules::arithmeticResult($operator, $type, $right);
            if ($result === null) {
                $this->addError('Semántico', $context, "Operación '{$operator}' inválida entre '{$type}' y '{$right}'.");
                return Type::invalid();
            }
            $type = $result;
        }
        return $type;
    }

    private function inferMultiplication(?\Context\MultiplicationContext $context): Type
    {
        if ($context === null) {
            return Type::invalid();
        }

        $type = $this->inferUnary($context->unary(0));
        $unaryCount = count($this->normalizeList($context->unary(null)));
        for ($i = 1; $i < $unaryCount; $i++) {
            $operator = $context->getChild(($i * 2) - 1)?->getText() ?? '*';
            $right = $this->inferUnary($context->unary($i));
            $result = TypeRules::arithmeticResult($operator, $type, $right);
            if ($result === null) {
                $this->addError('Semántico', $context, "Operación '{$operator}' inválida entre '{$type}' y '{$right}'.");
                return Type::invalid();
            }
            $type = $result;
        }
        return $type;
    }

    private function inferUnary(?\Context\UnaryContext $context): Type
    {
        if ($context === null) {
            return Type::invalid();
        }

        if ($context->primary() !== null) {
            return $this->inferPrimary($context->primary());
        }

        $operator = $context->getChild(0)?->getText() ?? '';
        $operand = $this->inferUnary($context->unary());

        return match ($operator) {
            '!' => TypeRules::unaryNotResult($operand) ?? $this->invalidUnary($context, $operator, $operand),
            '-' => TypeRules::unaryMinusResult($operand) ?? $this->invalidUnary($context, $operator, $operand),
            '&' => $this->inferReferenceType($context, $operand),
            '*' => $operand->isPointer()
                ? ($operand->pointedType ?? Type::invalid())
                : $this->invalidUnary($context, $operator, $operand),
            default => Type::invalid(),
        };
    }

    private function inferPrimary(?\Context\PrimaryContext $context): Type
    {
        if ($context === null) {
            return Type::invalid();
        }

        if ($context->literal() !== null) {
            return $this->literalType($context->literal());
        }
        if ($context->arrayLiteral() !== null) {
            return $this->resolveType($context->arrayLiteral()->arrayType());
        }
        if ($context->qualifiedIdentifier() !== null) {
            $name = $this->qualifiedName($context->qualifiedIdentifier());
            $symbol = $this->symbolTable->resolve($name);
            if ($symbol === null) {
                $this->addError('Semántico', $context, "Identificador '{$name}' no declarado.");
                return Type::invalid();
            }
            return $symbol->type;
        }
        if ($context->functionCall() !== null) {
            return $this->inferFunctionCallReturnTypes($context->functionCall())[0] ?? Type::nil();
        }
        if ($context->arrayAccess() !== null) {
            return $this->inferArrayAccessType($context->arrayAccess());
        }

        return $context->expression() !== null ? $this->inferExpressionType($context->expression()) : Type::invalid();
    }

    /**
     * @return list<Type>
     */
    private function inferFunctionCallReturnTypes(?\Context\FunctionCallContext $context): array
    {
        if ($context === null || $context->qualifiedIdentifier() === null) {
            return [Type::invalid()];
        }

        $name = $this->qualifiedName($context->qualifiedIdentifier());
        $arguments = $context->argumentList() !== null
            ? $this->normalizeList($context->argumentList()->expression(null))
            : [];
        $argumentTypes = array_map(fn ($expr): Type => $this->inferExpressionType($expr), $arguments);

        if ($name === 'main') {
            $this->addError('Semántico', $context, 'La función main no puede ser invocada explícitamente.');
            return [Type::invalid()];
        }

        if (BuiltinRegistry::isBuiltin($name)) {
            return $this->validateBuiltinCall($name, $argumentTypes, $context);
        }

        $function = $this->functions[$name] ?? null;
        if ($function === null) {
            $this->addError('Semántico', $context, "La función '{$name}' no existe.");
            return [Type::invalid()];
        }

        if (count($argumentTypes) !== count($function->parameterTypes)) {
            $this->addError(
                'Semántico',
                $context,
                "La función '{$name}' espera " . count($function->parameterTypes) . ' argumento(s).'
            );
            return $function->returnTypes;
        }

        foreach ($function->parameterTypes as $index => $expectedType) {
            if (!isset($argumentTypes[$index])) {
                continue;
            }
            if (!TypeRules::assignmentAllowed($expectedType, $argumentTypes[$index])) {
                $this->addError(
                    'Semántico',
                    $context,
                    "El argumento {$index} de '{$name}' no coincide: se esperaba '{$expectedType}' y se obtuvo '{$argumentTypes[$index]}'."
                );
            }
        }

        return $function->returnTypes === [] ? [Type::nil()] : $function->returnTypes;
    }

    private function inferArrayAccessType(?\Context\ArrayAccessContext $context): Type
    {
        if ($context === null || $context->qualifiedIdentifier() === null) {
            return Type::invalid();
        }

        $baseName = $this->qualifiedName($context->qualifiedIdentifier());
        $symbol = $this->symbolTable->resolve($baseName);
        if ($symbol === null) {
            $this->addError('Semántico', $context, "Identificador '{$baseName}' no declarado.");
            return Type::invalid();
        }

        $type = $symbol->type;
        foreach ($this->normalizeList($context->expression(null)) as $indexExpr) {
            $indexType = $this->inferExpressionType($indexExpr);
            if ($indexType->name !== Type::INT32) {
                $this->addError('Semántico', $indexExpr, 'El índice de arreglo debe ser int32.');
            }

            if ($type->isArray()) {
                $type = $type->elementType ?? Type::invalid();
            } elseif ($type->name === Type::STRING) {
                $type = Type::rune();
            } else {
                $this->addError('Semántico', $context, "El valor '{$baseName}' no es indexable.");
                return Type::invalid();
            }
        }

        return $type;
    }

    /**
     * @return list<Type>
     */
    private function validateBuiltinCall(string $name, array $argumentTypes, \Context\FunctionCallContext $context): array
    {
        if ($name === BuiltinRegistry::PRINTLN) {
            return [Type::nil()];
        }

        if ($name === BuiltinRegistry::LEN) {
            if (count($argumentTypes) !== 1) {
                $this->addError('Semántico', $context, "len requiere exactamente 1 argumento.");
            } elseif (
                !isset($argumentTypes[0]) ||
                (!$argumentTypes[0]->isArray() && $argumentTypes[0]->name !== Type::STRING)
            ) {
                $this->addError('Semántico', $context, 'len solo acepta arreglos o strings.');
            }
            return [Type::int32()];
        }

        if ($name === BuiltinRegistry::NOW) {
            if ($argumentTypes !== []) {
                $this->addError('Semántico', $context, 'now no acepta argumentos.');
            }
            return [Type::string()];
        }

        if ($name === BuiltinRegistry::SUBSTR) {
            if (count($argumentTypes) !== 3) {
                $this->addError('Semántico', $context, 'substr requiere exactamente 3 argumentos.');
            } else {
                if ($argumentTypes[0]->name !== Type::STRING) {
                    $this->addError('Semántico', $context, 'El primer argumento de substr debe ser string.');
                }
                if ($argumentTypes[1]->name !== Type::INT32 || $argumentTypes[2]->name !== Type::INT32) {
                    $this->addError('Semántico', $context, 'substr requiere índices int32.');
                }
            }
            return [Type::string()];
        }

        if ($name === BuiltinRegistry::TYPEOF) {
            if (count($argumentTypes) !== 1) {
                $this->addError('Semántico', $context, 'typeOf requiere exactamente 1 argumento.');
            }
            return [Type::string()];
        }

        return [Type::invalid()];
    }

    private function inferReferenceType(\Context\UnaryContext $context, Type $operand): Type
    {
        $innerUnary = $context->unary();
        if ($innerUnary === null || !$this->isAddressableUnary($innerUnary)) {
            $this->addError('Semántico', $context, 'El operador & solo puede aplicarse a identificadores o accesos indexados.');
            return Type::invalid();
        }

        return Type::pointerTo($operand);
    }

    private function invalidUnary(\Context\UnaryContext $context, string $operator, Type $operand): Type
    {
        $this->addError('Semántico', $context, "El operador '{$operator}' no es válido para '{$operand}'.");
        return Type::invalid();
    }

    private function resolveType(mixed $typeContext): Type
    {
        if ($typeContext === null) {
            return Type::invalid();
        }

        if ($typeContext instanceof \Context\TypeContext) {
            if ($typeContext->baseType() !== null) {
                return $this->resolveType($typeContext->baseType());
            }
            if ($typeContext->arrayType() !== null) {
                return $this->resolveType($typeContext->arrayType());
            }
            if ($typeContext->pointerType() !== null) {
                return $this->resolveType($typeContext->pointerType());
            }
        }

        if ($typeContext instanceof \Context\BaseTypeContext) {
            return match ($typeContext->getText()) {
                'int32', 'int' => Type::int32(),
                'float32' => Type::float32(),
                'bool' => Type::bool(),
                'rune' => Type::rune(),
                'string' => Type::string(),
                default => Type::invalid(),
            };
        }

        if ($typeContext instanceof \Context\ArrayTypeContext) {
            $lengthExpr = $typeContext->expression();
            $length = $this->constantIntValue($lengthExpr);
            if ($length === null) {
                $this->addError('Semántico', $typeContext, 'El tamaño del arreglo debe poder determinarse en compilación.');
            }
            return Type::arrayOf(
                $typeContext->type() !== null ? $this->resolveType($typeContext->type()) : Type::invalid(),
                $length
            );
        }

        if ($typeContext instanceof \Context\PointerTypeContext) {
            return Type::pointerTo($typeContext->type() !== null ? $this->resolveType($typeContext->type()) : Type::invalid());
        }

        return Type::invalid();
    }

    private function literalType(\Context\LiteralContext $context): Type
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

    private function qualifiedName(\Context\QualifiedIdentifierContext $context): string
    {
        return $context->getText();
    }

    private function isAssignableExpression(\Context\ExpressionContext $context): bool
    {
        $logicalOr = $context->logicalOr();
        $logicalAnd = $logicalOr?->logicalAnd(0);
        $equality = $logicalAnd?->equality(0);
        $comparison = $equality?->comparison(0);
        $addition = $comparison?->addition(0);
        $multiplication = $addition?->multiplication(0);
        $unary = $multiplication?->unary(0);
        $primary = $unary?->primary();

        if ($primary !== null) {
            return $primary->qualifiedIdentifier() !== null || $primary->arrayAccess() !== null;
        }

        return $unary !== null && $unary->getChild(0)?->getText() === '*';
    }

    private function symbolFromExpression(\Context\ExpressionContext $context): ?Symbol
    {
        $logicalOr = $context->logicalOr();
        $logicalAnd = $logicalOr?->logicalAnd(0);
        $equality = $logicalAnd?->equality(0);
        $comparison = $equality?->comparison(0);
        $addition = $comparison?->addition(0);
        $multiplication = $addition?->multiplication(0);
        $unary = $multiplication?->unary(0);
        $primary = $unary?->primary();

        if ($primary?->qualifiedIdentifier() !== null) {
            return $this->symbolTable->resolve($this->qualifiedName($primary->qualifiedIdentifier()));
        }
        if ($primary?->arrayAccess() !== null) {
            return $this->symbolTable->resolve($this->qualifiedName($primary->arrayAccess()->qualifiedIdentifier()));
        }

        return null;
    }

    private function isAddressableUnary(\Context\UnaryContext $context): bool
    {
        if ($context->primary() === null) {
            return false;
        }

        return $context->primary()->qualifiedIdentifier() !== null || $context->primary()->arrayAccess() !== null;
    }

    /**
     * @return list<Type>
     */
    private function expandExpressionTypes(\Context\ExpressionListContext $context): array
    {
        $expressions = $this->normalizeList($context->expression(null));
        if (count($expressions) === 1) {
            $functionCall = $this->extractFunctionCall($expressions[0]);
            if ($functionCall !== null) {
                return $this->inferFunctionCallReturnTypes($functionCall);
            }
        }

        return array_map(fn ($expression): Type => $this->inferExpressionType($expression), $expressions);
    }

    /**
     * @return list<mixed>
     */
    private function expandConstantValues(\Context\ExpressionListContext $context): array
    {
        $expressions = $this->normalizeList($context->expression(null));
        if (count($expressions) === 1) {
            $functionCall = $this->extractFunctionCall($expressions[0]);
            if ($functionCall !== null) {
                $name = $functionCall->qualifiedIdentifier() !== null
                    ? $this->qualifiedName($functionCall->qualifiedIdentifier())
                    : '';
                if ($name !== '') {
                    return [$this->constantValueOfFunctionCall($functionCall, $name)];
                }
            }
        }

        return array_map(fn ($expression) => $this->constantValueOfExpression($expression), $expressions);
    }

    private function extractFunctionCall(\Context\ExpressionContext $context): ?\Context\FunctionCallContext
    {
        $primary = $context->logicalOr()?->logicalAnd(0)?->equality(0)?->comparison(0)?->addition(0)?->multiplication(0)?->unary(0)?->primary();
        return $primary?->functionCall();
    }

    private function constantValueOfExpression(?\Context\ExpressionContext $context): mixed
    {
        if ($context === null) {
            return null;
        }

        $primary = $context->logicalOr()?->logicalAnd(0)?->equality(0)?->comparison(0)?->addition(0)?->multiplication(0)?->unary(0)?->primary();
        if ($primary?->literal() !== null) {
            $literal = $primary->literal();
            if ($literal->INT_LITERAL() !== null) {
                return (int) $literal->INT_LITERAL()->getText();
            }
            if ($literal->FLOAT_LITERAL() !== null) {
                return (float) $literal->FLOAT_LITERAL()->getText();
            }
            if ($literal->STRING_LITERAL() !== null) {
                return stripcslashes(substr($literal->STRING_LITERAL()->getText(), 1, -1));
            }
            if ($literal->RUNE_LITERAL() !== null) {
                return trim($literal->RUNE_LITERAL()->getText(), "'");
            }
            return match ($literal->getText()) {
                'true' => true,
                'false' => false,
                'nil' => null,
                default => null,
            };
        }

        if ($primary?->functionCall() !== null && $primary->functionCall()->qualifiedIdentifier() !== null) {
            return $this->constantValueOfFunctionCall(
                $primary->functionCall(),
                $this->qualifiedName($primary->functionCall()->qualifiedIdentifier())
            );
        }

        return null;
    }

    private function constantValueOfFunctionCall(\Context\FunctionCallContext $context, string $name): mixed
    {
        $args = $context->argumentList() !== null ? $this->normalizeList($context->argumentList()->expression(null)) : [];

        return match ($name) {
            BuiltinRegistry::NOW => date('Y-m-d H:i:s'),
            BuiltinRegistry::TYPEOF => isset($args[0]) ? (string) $this->inferExpressionType($args[0]) : null,
            BuiltinRegistry::LEN => isset($args[0]) ? $this->constantLengthOfExpression($args[0]) : null,
            BuiltinRegistry::SUBSTR => $this->constantSubstring($args),
            default => null,
        };
    }

    private function constantLengthOfExpression(\Context\ExpressionContext $expression): ?int
    {
        $value = $this->constantValueOfExpression($expression);
        if (is_string($value)) {
            return strlen($value);
        }

        $type = $this->inferExpressionType($expression);
        return $type->isArray() ? $type->length : null;
    }

    /**
     * @param list<\Context\ExpressionContext> $args
     */
    private function constantSubstring(array $args): ?string
    {
        if (count($args) !== 3) {
            return null;
        }

        $string = $this->constantValueOfExpression($args[0]);
        $start = $this->constantValueOfExpression($args[1]);
        $length = $this->constantValueOfExpression($args[2]);

        if (!is_string($string) || !is_int($start) || !is_int($length)) {
            return null;
        }

        return substr($string, $start, $length);
    }

    private function constantIntValue(?\Context\ExpressionContext $context): ?int
    {
        $value = $this->constantValueOfExpression($context);
        return is_int($value) ? $value : null;
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

    /**
     * @return list<object>
     */
    private function collectIdentifiers(?\Context\IdentifierListContext $context): array
    {
        return $context === null ? [] : $this->normalizeList($context->IDENTIFIER(null));
    }

    private function allocateOffsetIfNeeded(Type $type): ?int
    {
        if ($this->currentFunction === null) {
            return null;
        }

        $size = max(8, $this->align8($type->sizeInBytes()));
        $this->currentStackOffset += $size;
        return $this->currentStackOffset;
    }

    private function align8(int $value): int
    {
        return (int) (ceil($value / 8) * 8);
    }

    private function align16(int $value): int
    {
        if ($value === 0) {
            return 0;
        }
        return (int) (ceil($value / 16) * 16);
    }

    private function safeDefine(Symbol $symbol, mixed $context): void
    {
        try {
            $this->symbolTable->define($symbol);
        } catch (\RuntimeException $exception) {
            $this->addError('Semántico', $context, $exception->getMessage());
        }
    }

    private function addError(string $type, mixed $context, string $message): void
    {
        [$line, $column] = $this->lineCol($context);
        $this->diagnostics->add($type, $message, $line, $column);
    }

    /**
     * @return array{0:int,1:int}
     */
    private function lineCol(mixed $context): array
    {
        if ($context !== null && method_exists($context, 'getStart')) {
            $start = $context->getStart();
            if ($start !== null) {
                return [(int) $start->getLine(), (int) $start->getCharPositionInLine()];
            }
        }

        if ($context !== null && method_exists($context, 'getLine')) {
            return [(int) $context->getLine(), (int) $context->getCharPositionInLine()];
        }

        return [0, 0];
    }

    private function tokenLine(mixed $tokenNode): int
    {
        if ($tokenNode !== null && method_exists($tokenNode, 'getSymbol') && $tokenNode->getSymbol() !== null) {
            return (int) $tokenNode->getSymbol()->getLine();
        }

        return 0;
    }

    private function tokenColumn(mixed $tokenNode): int
    {
        if ($tokenNode !== null && method_exists($tokenNode, 'getSymbol') && $tokenNode->getSymbol() !== null) {
            return (int) $tokenNode->getSymbol()->getCharPositionInLine();
        }

        return 0;
    }
}
