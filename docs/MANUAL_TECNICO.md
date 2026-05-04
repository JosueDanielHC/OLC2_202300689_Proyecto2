# Manual técnico — Compilador Golampi (Proyecto 2)

## 1. Arquitectura del sistema

El sistema sigue un modelo **cliente–servidor monolítico**:

- **Cliente**: HTML/CSS/JavaScript estático en `Proyecto2/frontend/`. Envía el código por `fetch` (POST JSON) al backend.
- **Servidor**: PHP bajo `Proyecto2/backend/public/compile.php`, que instancia el pipeline de compilación y devuelve JSON.

**Proyecto 1** (`Proyecto1/backend/`) aporta exclusivamente:

- gramática ANTLR `Golampi.g4`;
- clases generadas (`GolampiLexer`, `GolampiParser`, visitors base);
- dependencia `antlr/antlr4-php-runtime` vía Composer.

**Proyecto 2** implementa análisis semántico propio, generación ARM64 y orquestación de ensamblado/ejecución, sin modificar los visitores del intérprete del Proyecto 1.

```
┌─────────────┐     POST JSON      ┌──────────────────┐
│  Frontend   │ ─────────────────► │ compile.php      │
│  (browser)  │ ◄───────────────── │ CompilerPipeline │
└─────────────┘     JSON ARM64     └────────┬─────────┘
                                            │
                    ┌───────────────────────┼───────────────────────┐
                    ▼                       ▼                       ▼
             ParsePipeline           SemanticVisitor2        Arm64CodegenVisitor
             (ANTLR P1)            (tabla de símbolos)      (ensamblador)
                                            │                       │
                                            └───────────┬───────────┘
                                                        ▼
                                                  AsmRunner → QEMU
```

## 2. Lenguajes y tecnologías

| Capa | Tecnología |
|------|------------|
| Lenguaje de implementación | PHP 8 (tipado estricto en el código nuevo) |
| Análisis léxico/sintáctico | ANTLR 4, runtime PHP |
| Semántica y código | Visitores PHP propios (namespace `Proyecto2\`) |
| Ensamblado / enlace / emulación | `aarch64-linux-gnu-as`, `aarch64-linux-gnu-ld`, `qemu-aarch64` (sistema o toolchain local) |
| Interfaz | HTML5, CSS, JavaScript (sin framework) |

## 3. Módulos y clases principales

### Bootstrap y rutas

| Archivo | Rol |
|---------|-----|
| `Proyecto2/backend/bootstrap/project.php` | Autoload PSR-4 del prefijo `Proyecto2\`. |
| `Proyecto2/backend/bootstrap/antlr.php` | Carga `vendor` y fuentes generadas desde `Proyecto1/backend/`. |
| `Proyecto2/backend/src/Support/Paths.php` | Raíces del repo, salida `program.s`, rutas del toolchain embebido. |

### Pipeline

| Clase | Responsabilidad |
|-------|-----------------|
| `ParsePipeline` | Normaliza fuente, ejecuta lexer/parser ANTLR, recolecta diagnósticos léxicos/sintácticos. |
| `CompilerPipeline` | Ordena parse → semántica → codegen → escritura de `.s` → `AsmRunner` → reportes. |
| `SourceNormalizer` | Preprocesado ligero del texto de entrada. |
| `CompilationResult` / `ParseResult` | DTOs del resultado por fase. |

### Semántica (`Proyecto2/backend/src/Semantic/`)

| Componente | Responsabilidad |
|------------|-----------------|
| `SemanticVisitor2` | Recorrido del árbol ANTLR: scopes, tipos, funciones, sentencias de control, built-ins. |
| `SymbolTable`, `Scope`, `Symbol`, `FunctionSymbol` | Modelo de símbolos y metadatos (p. ej. offset de pila). |
| `Type`, `TypeRules`, `BuiltinRegistry` | Modelo de tipos y reglas de compatibilidad. |
| `SemanticModel` | Resultado agregado consumido por codegen. |

### Generación y ejecución (`Proyecto2/backend/src/Codegen/`)

| Clase | Responsabilidad |
|-------|-----------------|
| `Arm64CodegenVisitor` | Traducción del AST a texto ARM64 (aritmética entera/flotante, flujo, llamadas, arreglos según soporte). |
| `AsmBuilder`, `LabelGenerator`, `StackFrameLayout` | Utilidades de emisión y marcos de activación. |
| `RuntimeEmitter` | Rutinas de runtime enlazadas o embebidas necesarias para el modelo de ejecución. |
| `AsmRunner` | Invoca ensamblador, linker y QEMU con variables de entorno para bibliotecas del sysroot local. |
| `ExecutionResult` | Salida estructurada de la ejecución. |

### Diagnósticos y reportes

| Ubicación | Rol |
|-----------|-----|
| `Diagnostics/*` | `DiagnosticBag`, listeners de lexer/parser, tipos de mensaje. |
| `Reports/*` | Renderizado de reportes de errores y tabla de símbolos para texto o descarga. |

## 4. Flujo del programa

1. **Entrada HTTP**: `compile.php` lee JSON, extrae `code`.
2. **Parseo**: `ParsePipeline::parse` → árbol `program` o errores.
3. **Semántica**: `SemanticVisitor2::visit` sobre el árbol; llena `SemanticModel` y diagnósticos.
4. **Codegen** (si no hay errores): `Arm64CodegenVisitor::generate` → cadena ensamblador y metadatos.
5. **Persistencia**: se escribe `Proyecto2/output/asm/program.s`.
6. **Ejecución** (si no hay bloqueos declarados en metadatos, p. ej. punteros): `AsmRunner::run` ensambla, enlaza y ejecuta con QEMU.
7. **Respuesta**: JSON con ensamblador, errores, ejecución, símbolos y reportes.

## 5. Decisiones técnicas importantes

1. **Reutilización de ANTLR sin duplicar gramática**  
   Se centraliza la carga en `antlr.php` y `Paths::legacyBackendRoot()` apunta a `Proyecto1/backend`, evitando copias divergentes del parser.

2. **Namespace aislado `Proyecto2\`**  
   El código nuevo no mezcla clases con el namespace `Golampi\` del intérprete, reduciendo acoplamiento accidental.

3. **Toolchain opcional embebido**  
   `AsmRunner` prioriza binarios bajo `Proyecto2/toolchain/root/usr/bin` para que la evaluación sea reproducible en entornos sin paquetes ARM64 del sistema.

4. **Ejecución condicionada por limitaciones de codegen**  
   Si el metadato de codegen indica bloqueos (p. ej. punteros no soportados), no se invoca QEMU para evitar binarios incoherentes.

5. **Un solo punto HTTP para el compilador**  
   `compile.php` concentra el contrato JSON del Proyecto 2; el `api.php` del Proyecto 1 permanece para el flujo de intérprete histórico.

## 6. Estructura de carpetas (resumen)

```
Proyecto1/backend/     # Gramática, generated/, intérprete, api.php, composer.json
Proyecto1/frontend/    # GUI del intérprete
Proyecto2/backend/     # Compilador ARM64 (PHP)
Proyecto2/frontend/    # GUI del compilador
Proyecto2/output/asm/  # Salida program.s, binarios de prueba (parcialmente ignorados)
Proyecto2/toolchain/   # Sysroot y binarios locales (downloads/ ignorado)
docs/                  # Manuales y documentación de entrega
tests/                 # Ejemplos Golampi
```

## 7. Referencias en el repositorio

- [Explicación ampliada del flujo](../Proyecto2/docs/EXPLICACION_PROYECTO.md)
- [Auditoría de integración con Proyecto 1](../Proyecto2/docs/AUDITORIA_PROYECTO1.md)
