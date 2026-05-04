<?php

declare(strict_types=1);

namespace Proyecto2\Semantic;

final class Type
{
    public const INT32 = 'int32';
    public const FLOAT32 = 'float32';
    public const BOOL = 'bool';
    public const RUNE = 'rune';
    public const STRING = 'string';
    public const NIL = 'nil';
    public const INVALID = 'invalid';

    private function __construct(
        public readonly string $name,
        public readonly ?Type $elementType = null,
        public readonly ?int $length = null,
        public readonly ?Type $pointedType = null
    ) {
    }

    public static function int32(): self
    {
        return new self(self::INT32);
    }

    public static function float32(): self
    {
        return new self(self::FLOAT32);
    }

    public static function bool(): self
    {
        return new self(self::BOOL);
    }

    public static function rune(): self
    {
        return new self(self::RUNE);
    }

    public static function string(): self
    {
        return new self(self::STRING);
    }

    public static function nil(): self
    {
        return new self(self::NIL);
    }

    public static function invalid(): self
    {
        return new self(self::INVALID);
    }

    public static function arrayOf(Type $elementType, ?int $length = null): self
    {
        return new self('array', $elementType, $length);
    }

    public static function pointerTo(Type $pointedType): self
    {
        return new self('pointer', null, null, $pointedType);
    }

    public function isArray(): bool
    {
        return $this->name === 'array';
    }

    public function isPointer(): bool
    {
        return $this->name === 'pointer';
    }

    public function isNil(): bool
    {
        return $this->name === self::NIL;
    }

    public function isInvalid(): bool
    {
        return $this->name === self::INVALID;
    }

    public function isNumeric(): bool
    {
        return in_array($this->name, [self::INT32, self::FLOAT32, self::RUNE], true);
    }

    public function equals(Type $other): bool
    {
        if ($this->name !== $other->name) {
            return false;
        }

        if ($this->isArray()) {
            return $this->length === $other->length
                && $this->elementType !== null
                && $other->elementType !== null
                && $this->elementType->equals($other->elementType);
        }

        if ($this->isPointer()) {
            return $this->pointedType !== null
                && $other->pointedType !== null
                && $this->pointedType->equals($other->pointedType);
        }

        return true;
    }

    public function sizeInBytes(): int
    {
        if ($this->isArray()) {
            $length = $this->length ?? 1;
            $elementSize = $this->elementType?->sizeInBytes() ?? 8;
            return max(8, $length * $elementSize);
        }

        return 8;
    }

    public function __toString(): string
    {
        if ($this->isArray()) {
            $length = $this->length ?? 0;
            return '[' . $length . ']' . (string) $this->elementType;
        }

        if ($this->isPointer()) {
            return '*' . (string) $this->pointedType;
        }

        return $this->name;
    }
}
