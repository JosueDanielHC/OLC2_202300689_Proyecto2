<?php

declare(strict_types=1);

use Proyecto2\Pipeline\CompilerPipeline;

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

require_once __DIR__ . '/../bootstrap/project.php';

$payload = json_decode(file_get_contents('php://input') ?: '[]', true);
$source = is_array($payload) ? (string) ($payload['code'] ?? '') : '';

$pipeline = new CompilerPipeline();
$result = $pipeline->compile($source);

$symbols = [];
if ($result->semanticModel !== null) {
    foreach ($result->semanticModel->symbolTable->getDeclaredSymbols() as $symbol) {
        $symbols[] = [
            'name' => $symbol->name,
            'type' => (string) $symbol->type,
            'scope' => $symbol->scopeName,
            'value' => $symbol->value,
            'line' => $symbol->line ?? 0,
            'column' => $symbol->column ?? 0,
            'offset' => $symbol->stackOffset ?? 0,
            'category' => $symbol->category,
        ];
    }
}

echo json_encode([
    'ok' => $result->ok,
    'asm' => $result->assembly,
    'errors' => $result->diagnostics->toArray(),
    'codegen' => $result->codegenMetadata,
    'execution' => $result->execution?->toArray(),
    'symbolTable' => $symbols,
    'reportErrors' => $result->errorReport,
    'reportSymbols' => $result->symbolTableReport,
    'downloads' => [
        'asm' => '../output/asm/program.s',
        'errors' => $result->errorReport,
        'symbols' => $result->symbolTableReport,
    ],
    'normalizedSource' => $result->normalizedSource,
], JSON_UNESCAPED_UNICODE);
