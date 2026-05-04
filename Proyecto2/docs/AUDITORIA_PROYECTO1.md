# Auditoría de Proyecto 1

## Objetivo

Este documento fija el contrato de reutilización entre Proyecto 1 y `Proyecto2/`.
Todo lo descrito aquí fue auditado antes de implementar el compilador nuevo; el código
de análisis e intérprete del Proyecto 1 no debe alterarse desde el flujo del compilador ARM64.

## Recursos reutilizados (solo lectura para Proyecto 2)

Rutas relativas a la raíz del repositorio:

- Gramática ANTLR: `Proyecto1/backend/grammar/Golampi.g4`
- Lexer generado: `Proyecto1/backend/generated/GolampiLexer.php`
- Parser generado: `Proyecto1/backend/generated/GolampiParser.php`
- Visitor base generado: `Proyecto1/backend/generated/GolampiBaseVisitor.php`
- Visitor interface generado: `Proyecto1/backend/generated/GolampiVisitor.php`
- Runtime ANTLR para PHP: `Proyecto1/backend/vendor/` (instalar con `composer install`)

## Flujo actual del Proyecto 1

El sistema es un intérprete web en PHP.

1. Recibe el código fuente.
2. Aplica una normalización ligera para variantes usadas en pruebas.
3. Ejecuta lexer y parser ANTLR sobre `Golampi.g4`.
4. Ejecuta un visitor semántico propio.
5. Si no hay errores, ejecuta un visitor de interpretación.
6. Devuelve salida, errores y reportes.

Entradas principales:

- `Proyecto1/backend/api.php`
- `Proyecto1/backend/snippet_runner.php`

## Capacidades detectadas

La gramática actual ya cubre:

- variables globales y locales
- declaración corta `:=`
- constantes
- tipos primitivos `int32`, `int`, `float32`, `bool`, `rune`, `string`
- `nil`
- arreglos y acceso indexado
- punteros
- expresiones aritméticas, relacionales y lógicas
- `if`, `switch`, `for`
- `break`, `continue`, `return`
- funciones con parámetros y retorno múltiple
- built-ins como `fmt.Println`, `len`, `now`, `substr`, `typeOf`

## Limitaciones del Proyecto 1 respecto a Proyecto 2

No existe backend de compilación a ARM64 en la implementación previa.
El flujo actual interpreta el programa; no genera ensamblador ni binarios.

## Reglas de integración

- El compilador nuevo no debe modificar la gramática ni los visitores del Proyecto 1 como parte de su flujo normal.
- `Proyecto2` debe cargar el parser y lexer existentes en modo solo lectura.
- Los visitors del Proyecto 1 no se alteran ni se usan como flujo final de compilación ARM64.
- Los visitors nuevos de `Proyecto2` trabajan sobre el mismo árbol sintáctico generado por ANTLR.
- Si alguna característica futura requiriera tocar la gramática, primero debe documentarse
  la necesidad y justificar la extensión mínima.

## Contrato técnico

`Proyecto2/backend/bootstrap/antlr.php` es el único punto autorizado para enlazar con los
artefactos ANTLR del Proyecto 1. Toda otra capa del compilador nuevo depende de ese
bootstrap y no referencia directamente rutas del Proyecto 1 fuera de `Paths::legacyBackendRoot()`.
