<?php

/**
 * API HTTP para el intérprete Golampi.
 * Acepta POST con JSON { "code": "..." } y devuelve JSON con resultado, errores y reportes.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

$input = file_get_contents('php://input');
$body = json_decode($input, true);
$code = $body['code'] ?? '';

// Normalización ligera para soportar variantes usadas en pruebas:
// - var a, b = expr    -> a, b := expr
// - rune(expr)         -> (expr)  (cast sintáctico no soportado por gramática actual)
if ($code !== '') {
    $code = preg_replace(
        '/\bvar\s+([_a-zA-Z][_a-zA-Z0-9]*(?:\s*,\s*[_a-zA-Z][_a-zA-Z0-9]*)+)\s*=\s*/',
        '$1 := ',
        $code
    ) ?? $code;
    $code = preg_replace('/\brune\s*\(/', '(', $code) ?? $code;
    // Soporte para switch con rango entero: case x..y:
    // Se reescribe a case x, x+1, ..., y:
    $code = preg_replace_callback(
        '/(^[ \t]*case[ \t]+)(-?\d+)[ \t]*\.\.[ \t]*(-?\d+)([ \t]*:)/m',
        static function (array $m): string {
            $prefix = $m[1];
            $start = (int) $m[2];
            $end = (int) $m[3];
            $suffix = $m[4];
            if (abs($end - $start) > 1000) {
                // Evita expandir rangos excesivamente grandes.
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

if ($code === '') {
    echo json_encode([
        'ok' => true,
        'output' => '',
        'errors' => [],
        'symbolTable' => [],
        'reportResult' => '',
        'reportErrors' => '',
        'reportSymbols' => '',
    ]);
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/generated/GolampiLexer.php';
require_once __DIR__ . '/generated/GolampiParser.php';
require_once __DIR__ . '/generated/GolampiVisitor.php';
require_once __DIR__ . '/generated/GolampiBaseVisitor.php';

use Antlr\Antlr4\Runtime\CommonTokenStream;
use Antlr\Antlr4\Runtime\Error\Listeners\BaseErrorListener;
use Antlr\Antlr4\Runtime\InputStream;
use Antlr\Antlr4\Runtime\Recognizer;

$parseErrors = [];
$input = InputStream::fromString($code);
$lexer = new GolampiLexer($input);
$tokens = new CommonTokenStream($lexer);
$parser = new GolampiParser($tokens);
$parser->removeErrorListeners();
$parser->addErrorListener(new class($parseErrors) extends BaseErrorListener {
    private array $errors;
    public function __construct(array &$errors) { $this->errors = &$errors; }
    public function syntaxError($recognizer, $offendingSymbol, int $line, int $charPositionInLine, string $msg, $e): void {
        $this->errors[] = ['type' => 'Sintáctico', 'message' => $msg, 'line' => $line, 'column' => $charPositionInLine];
    }
});

$tree = $parser->program();
$semantic = new \Golampi\Visitors\SemanticVisitor();
$semantic->visit($tree);
$semanticErrors = $semantic->getErrorHandler()->getErrors();
$errors = array_merge(
    array_map(fn($e) => ['type' => $e['type'] ?? 'Semántico', 'message' => $e['message'] ?? '', 'line' => $e['line'] ?? 0, 'column' => $e['column'] ?? 0], $semanticErrors),
    $parseErrors
);

$symbolTable = $semantic->getSymbolTable();
$symbolTableRows = [];
$declared = $symbolTable->getDeclaredSymbols();
$scopeNames = ['global', 'función', 'bloque'];
foreach ($declared as $entry) {
    $level = $entry['scopeLevel'];
    $sym = $entry['symbol'];
    $scopeLabel = $scopeNames[min($level, 2)] ?? "nivel $level";
    if (!$sym instanceof \Golampi\interpreter\Symbol) continue;
    $symbolTableRows[] = [
        'name' => $sym->name,
        'type' => (string) $sym->type,
        'scope' => $scopeLabel,
        'line' => $sym->line ?? 0,
        'column' => $sym->column ?? 0,
    ];
}

$output = '';
$runtimeError = null;
if ($tree !== null && !$semantic->hasErrors() && count($parseErrors) === 0) {
    try {
        $exec = new \Golampi\Visitors\ExecutionVisitor($symbolTable);
        $exec->visit($tree);
        $output = $exec->getOutput();
    } catch (\RuntimeException $e) {
        $runtimeError = ['type' => 'Ejecución', 'message' => $e->getMessage(), 'line' => 0, 'column' => 0];
    }
}
if ($runtimeError !== null) {
    $errors[] = $runtimeError;
}

$reportGen = new \Golampi\Utils\ReportGenerator($semantic->getErrorHandler(), $symbolTable);
$reportResult = $reportGen->analysisReport();
$reportErrors = $reportGen->errorsReport();
$reportSymbols = $reportGen->symbolTableReport();

echo json_encode([
    'ok' => count($errors) === 0,
    'output' => $output,
    'errors' => $errors,
    'symbolTable' => $symbolTableRows,
    'reportResult' => $reportResult,
    'reportErrors' => $reportErrors,
    'reportSymbols' => $reportSymbols,
], JSON_UNESCAPED_UNICODE);
