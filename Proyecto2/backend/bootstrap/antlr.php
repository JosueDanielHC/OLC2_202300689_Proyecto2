<?php

declare(strict_types=1);

use Proyecto2\Support\Paths;

require_once __DIR__ . '/../src/Support/Paths.php';

$legacyBackend = Paths::legacyBackendRoot();

require_once $legacyBackend . '/vendor/autoload.php';
require_once $legacyBackend . '/generated/GolampiLexer.php';
require_once $legacyBackend . '/generated/GolampiParser.php';
require_once $legacyBackend . '/generated/GolampiVisitor.php';
require_once $legacyBackend . '/generated/GolampiBaseVisitor.php';
