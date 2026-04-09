<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/generated/GolampiLexer.php';
require_once __DIR__ . '/generated/GolampiParser.php';
require_once __DIR__ . '/generated/GolampiVisitor.php';
require_once __DIR__ . '/generated/GolampiBaseVisitor.php';

use Antlr\Antlr4\Runtime\CommonTokenStream;
use Antlr\Antlr4\Runtime\InputStream;

$code = stream_get_contents(STDIN);
if ($code === false) {
    $code = '';
}
if ($code !== '') {
    $code = preg_replace(
        '/\bvar\s+([_a-zA-Z][_a-zA-Z0-9]*(?:\s*,\s*[_a-zA-Z][_a-zA-Z0-9]*)+)\s*=\s*/',
        '$1 := ',
        $code
    ) ?? $code;
    $code = preg_replace('/\brune\s*\(/', '(', $code) ?? $code;
    $code = preg_replace_callback(
        '/(^[ \t]*case[ \t]+)(-?\d+)[ \t]*\.\.[ \t]*(-?\d+)([ \t]*:)/m',
        static function (array $m): string {
            $prefix = $m[1];
            $start = (int) $m[2];
            $end = (int) $m[3];
            $suffix = $m[4];
            if (abs($end - $start) > 1000) {
                return $m[0];
            }
            $step = $start <= $end ? 1 : -1;
            $items = [];
            for ($i = $start; ; $i += $step) {
                $items[] = (string) $i;
                if ($i === $end) {
                    break;
                }
            }
            return $prefix . implode(', ', $items) . $suffix;
        },
        $code
    ) ?? $code;
}

$input = InputStream::fromString($code);
$lexer = new GolampiLexer($input);
$tokens = new CommonTokenStream($lexer);
$parser = new GolampiParser($tokens);
$tree = $parser->program();

$semantic = new \Golampi\Visitors\SemanticVisitor();
$semantic->visit($tree);
$errorHandler = $semantic->getErrorHandler();
$errors = $errorHandler->getErrors();

$output = '';
if (!$semantic->hasErrors()) {
    try {
        $exec = new \Golampi\Visitors\ExecutionVisitor($semantic->getSymbolTable());
        $exec->visit($tree);
        $output = $exec->getOutput();
    } catch (\RuntimeException $e) {
        $errors[] = ['type' => 'Ejecución', 'message' => $e->getMessage(), 'line' => 0, 'column' => 0];
    }
}

echo json_encode([
    'errors' => $errors,
    'output' => $output,
], JSON_UNESCAPED_UNICODE);

