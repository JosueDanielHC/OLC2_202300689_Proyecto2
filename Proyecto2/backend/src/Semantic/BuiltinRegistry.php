<?php

declare(strict_types=1);

namespace Proyecto2\Semantic;

final class BuiltinRegistry
{
    public const PRINTLN = 'fmt.Println';
    public const LEN = 'len';
    public const NOW = 'now';
    public const SUBSTR = 'substr';
    public const TYPEOF = 'typeOf';

    public static function isBuiltin(string $name): bool
    {
        return in_array($name, [
            self::PRINTLN,
            self::LEN,
            self::NOW,
            self::SUBSTR,
            self::TYPEOF,
        ], true);
    }

    /**
     * @return list<Type>
     */
    public static function returnTypes(string $name, ?Type $arg0 = null): array
    {
        return match ($name) {
            self::PRINTLN => [Type::nil()],
            self::LEN => [Type::int32()],
            self::NOW => [Type::string()],
            self::SUBSTR => [Type::string()],
            self::TYPEOF => [Type::string()],
            default => [Type::invalid()],
        };
    }
}
