<?php

declare(strict_types=1);

namespace Proyecto2\Semantic;

final class TypeRules
{
    public static function assignmentAllowed(Type $target, Type $source): bool
    {
        if ($target->isInvalid() || $source->isInvalid()) {
            return false;
        }

        if ($source->isNil()) {
            return $target->isPointer();
        }

        if ($target->isArray() || $target->isPointer()) {
            return $target->equals($source);
        }

        if ($target->equals($source)) {
            return true;
        }

        return $target->name === Type::FLOAT32
            && in_array($source->name, [Type::INT32, Type::RUNE], true);
    }

    public static function arithmeticResult(string $operator, Type $left, Type $right): ?Type
    {
        if ($left->isInvalid() || $right->isInvalid()) {
            return Type::invalid();
        }

        if ($left->isNil() || $right->isNil()) {
            return null;
        }

        if ($operator === '+' && $left->name === Type::STRING && $right->name === Type::STRING) {
            return Type::string();
        }

        if ($operator === '*' && $left->name === Type::STRING && $right->name === Type::INT32) {
            return Type::string();
        }

        if ($operator === '*' && $left->name === Type::INT32 && $right->name === Type::STRING) {
            return Type::string();
        }

        if (!$left->isNumeric() || !$right->isNumeric()) {
            return null;
        }

        if ($operator === '%' && ($left->name === Type::FLOAT32 || $right->name === Type::FLOAT32)) {
            return null;
        }

        if ($left->name === Type::FLOAT32 || $right->name === Type::FLOAT32) {
            return Type::float32();
        }

        return Type::int32();
    }

    public static function relationalResult(Type $left, Type $right): ?Type
    {
        if ($left->isNil() || $right->isNil()) {
            return null;
        }

        if ($left->isNumeric() && $right->isNumeric()) {
            return Type::bool();
        }

        if ($left->name === Type::STRING && $right->name === Type::STRING) {
            return Type::bool();
        }

        return null;
    }

    public static function equalityResult(Type $left, Type $right): ?Type
    {
        if ($left->isInvalid() || $right->isInvalid()) {
            return Type::invalid();
        }

        if ($left->isNil() && $right->isPointer()) {
            return Type::bool();
        }

        if ($right->isNil() && $left->isPointer()) {
            return Type::bool();
        }

        if ($left->equals($right)) {
            return Type::bool();
        }

        if ($left->isNumeric() && $right->isNumeric()) {
            return Type::bool();
        }

        return null;
    }

    public static function logicalResult(Type $left, Type $right): ?Type
    {
        if ($left->name === Type::BOOL && $right->name === Type::BOOL) {
            return Type::bool();
        }

        return null;
    }

    public static function unaryMinusResult(Type $operand): ?Type
    {
        if (!$operand->isNumeric()) {
            return null;
        }

        return $operand->name === Type::FLOAT32 ? Type::float32() : Type::int32();
    }

    public static function unaryNotResult(Type $operand): ?Type
    {
        return $operand->name === Type::BOOL ? Type::bool() : null;
    }
}
