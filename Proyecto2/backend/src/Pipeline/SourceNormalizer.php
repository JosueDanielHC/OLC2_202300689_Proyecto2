<?php

declare(strict_types=1);

namespace Proyecto2\Pipeline;

final class SourceNormalizer
{
    public function normalize(string $source): string
    {
        if ($source === '') {
            return '';
        }

        $normalized = preg_replace(
            '/\bvar\s+([_a-zA-Z][_a-zA-Z0-9]*(?:\s*,\s*[_a-zA-Z][_a-zA-Z0-9]*)+)\s*=\s*/',
            '$1 := ',
            $source
        );

        $normalized = $normalized ?? $source;
        $normalized = preg_replace('/\brune\s*\(/', '(', $normalized) ?? $normalized;
        $normalized = preg_replace_callback(
            '/(^[ \t]*case[ \t]+)(-?\d+)[ \t]*\.\.[ \t]*(-?\d+)([ \t]*:)/m',
            static function (array $match): string {
                $start = (int) $match[2];
                $end = (int) $match[3];
                if (abs($end - $start) > 1000) {
                    return $match[0];
                }

                $values = [];
                $step = $start <= $end ? 1 : -1;
                for ($current = $start; ; $current += $step) {
                    $values[] = (string) $current;
                    if ($current === $end) {
                        break;
                    }
                }

                return $match[1] . implode(', ', $values) . $match[4];
            },
            $normalized
        ) ?? $normalized;

        return $normalized;
    }
}
