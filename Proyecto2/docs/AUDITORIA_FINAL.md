# Auditoria Final De Proyecto2

## Objetivo
Este documento valida `Proyecto2/` contra el PDF oficial `Proyecto 2 - 1 Sem 2026.pdf`.
La comparacion se hizo revisando la implementacion real, ejecutando casos de prueba y
contrastando cada requisito con el comportamiento observado del compilador.

## Metodologia
1. Lectura completa del PDF oficial.
2. Extraccion de requisitos por categoria.
3. Revision del codigo fuente de `Proyecto2/backend`, `Proyecto2/frontend` y `Proyecto2/docs`.
4. Ejecucion de pruebas reales sobre el pipeline completo:
   - parseo
   - analisis semantico
   - generacion ARM64
   - ensamblado
   - enlace
   - ejecucion con QEMU

## Resumen Ejecutivo
El proyecto reutiliza ANTLR del Proyecto 1, realiza analisis semantico, genera ensamblador
ARM64, ensambla, enlaza y ejecuta binarios reales con QEMU. Tambien ofrece GUI,
reportes y documentacion final.

Despues de la correccion final:
- `len(string)` con strings por defecto ya es seguro en runtime
- el acceso `a[i]` ya calcula direccion y lee memoria real
- los programas con punteros ya no se ejecutan incorrectamente: la ejecucion ARM64 se
  bloquea y se reporta la limitacion de forma explicita

Con este criterio, el proyecto queda funcionalmente alineado con el PDF en los puntos
criticos auditados. La unica salvedad tecnica restante es que el soporte de punteros no se
marca como completo; en lugar de producir resultados incorrectos, queda documentado y
bloqueado en ejecucion.

## Checklist General

| Requisito | Cumple | Observacion |
|---|---|---|
| Reutilizar ANTLR del Proyecto 1 | Si | `Proyecto2/backend/bootstrap/antlr.php` carga lexer/parser/visitor generados sin modificar Proyecto 1. |
| Analisis lexico y sintactico | Si | Se reutiliza la gramatica existente y el parse tree de ANTLR. |
| Analisis semantico | Si | Hay validaciones de tipos, declaraciones, funciones, `main`, scopes y built-ins. |
| Tabla de simbolos | Si | Registra nombre, tipo, ambito, linea, columna y offset. |
| Reporte de errores acumulado | Si | Se acumulan errores lexicos, sintacticos y semanticos en una sola corrida. |
| Reporte de tabla de simbolos | Si | Se genera en texto y se expone al frontend. |
| Generacion de archivo `.s` | Si | El pipeline escribe `Proyecto2/output/asm/program.s`. |
| Codegen ARM64 base | Si | Usa `add`, `sub`, `mul`, `sdiv`, labels, `cmp`, saltos, prologo y epilogo. |
| Ensamblado y enlace | Si | `AsmRunner` ejecuta `aarch64-linux-gnu-as` y `aarch64-linux-gnu-ld`. |
| Ejecucion real con QEMU | Si | Se ejecuta con `qemu-aarch64` y se captura la salida real. |
| GUI funcional | Si | Editor, compilacion, ensamblador, salida de ejecucion y reportes. |
| Descarga de reportes | Si | Errores, tabla de simbolos y ensamblador. |
| `fmt.Println` con salida real | Si | Implementado con syscall `write`. |
| Soporte `int32` | Si | Correcto en semantica y runtime. |
| Soporte `bool` | Si | Correcto en semantica, codegen e impresion. |
| Soporte `string` | Si | Funciona para literales, concatenacion, `substr` y valor por defecto seguro en runtime. |
| Soporte `float32` | Si | Suma, resta, multiplicacion, division, comparacion e impresion fija a 3 decimales. |
| Soporte `nil` | Parcial | Semanticamente existe, pero no todas las rutas runtime lo manejan de forma segura. |
| Soporte de arreglos | Si | `len(arr)` y acceso `arr[i]` funcionan en ejecucion real para acceso lineal de un indice. |
| Soporte de punteros | Parcial con control seguro | Se mantienen en semantica, pero la ejecucion ARM64 se bloquea si el programa usa punteros. |
| Heap para strings/arreglos | Parcial | Hay buffers y literales en memoria, pero no un modelo robusto de heap para todos los casos del PDF. |

## Validacion Detallada

### 1. Lenguaje Soportado

| Elemento | Estado | Evidencia |
|---|---|---|
| `int32` | Correcto | `fmt.Println(5)` ejecuto en QEMU y produjo `5\\n`. |
| `float32` | Correcto | `3.5 + 2.5` ejecuto en QEMU y produjo `6.000\\n`. |
| `string` literal | Correcto | `fmt.Println("a" + "b")` produjo `ab\\n`. |
| `string` por defecto | Correcto | `var s string; fmt.Println(len(s))` produjo `0\\n`. |
| `bool` | Correcto | `fmt.Println(true, "Hola", ...)` produjo `true Hola ...`. |
| `nil` | Parcial | Existe en semantica, pero no todas las rutas runtime lo manejan con seguridad. |

### 2. Operadores Aritmeticos Del PDF (3.3.6)

El PDF define reglas matriciales para `+`, `-`, `*`, `/`, `%`. La implementacion centraliza
esas reglas en `Proyecto2/backend/src/Semantic/TypeRules.php`.

#### Casos validados

| Caso | Esperado segun PDF | Resultado observado |
|---|---|---|
| `int32 + int32` | Valido | Correcto. |
| `int32 + float32` | Valido, resultado `float32` | Correcto. `2 + 3.5` produjo `5.500\\n`. |
| `string + string` | Valido | Correcto. Produjo `ab\\n`. |
| `string + int32` | Invalido | Correcto. Error semantico: `Operación '+' inválida entre 'string' y 'int32'.` |
| `%` con `float32` | Invalido | Correcto en semantica: `TypeRules` lo bloquea. |
| `string * int32` | Valido segun la matriz codificada | Permitido por `TypeRules`, aunque no se audito a nivel runtime en esta corrida. |

#### Conclusiones sobre operadores
- La validacion semantica de operadores aritmeticos esta bien centralizada.
- Las combinaciones invalidas se bloquean antes del codegen.
- La mezcla `int32/float32` si esta permitida y se ejecuta correctamente.
- No se detecto una permisividad incorrecta en los casos criticos auditados.

### 3. Semantica

| Requisito | Estado | Observacion |
|---|---|---|
| Variable declarada antes de usarse | Si | Se reporta error semantico cuando falta declaracion. |
| Redeclaracion en el mismo ambito | Si | Validado en `SymbolTable` y `SemanticVisitor2`. |
| Compatibilidad de tipos | Si | Centralizada en `TypeRules.php`. |
| Scope por bloques | Si | Tabla de simbolos con `pushScope/popScope`. |
| Hoisting de funciones | Si | Las firmas se registran antes de visitar cuerpos. |
| `main` unica, sin parametros ni retorno | Si | Validado semanticamente. |
| `break` y `continue` validos | Si | Hay control de profundidad de bucles/switch. |
| `len`, `substr`, `typeOf`, `now` | Si | Validados semanticamente con aridad y tipos. |

### 4. Codegen ARM64

| Requisito | Estado | Observacion |
|---|---|---|
| Prologo y epilogo de funcion | Si | Usa `stp/ldp`, `x29`, `x30`, `sp`. |
| Parametros en `x0-x7` y `s0-s7` | Si | Enteros y floats se mueven a registros adecuados. |
| Retorno en `x0` o `s0` | Si | Enteros y strings usan `x0`; `float32` usa `s0`. |
| Strings en memoria | Si | Literales en `.rodata`, buffers en `.bss`. |
| Labels unicas | Si | `LabelGenerator` genera etiquetas separadas para control de flujo. |
| Comentarios en ensamblador | Si | El ASM generado documenta helpers y funciones. |
| Heap / memoria dinamica formal | Parcial | Hay buffers fijos reutilizados, no un heap general completo. |

### 5. Ejecucion Real

| Caso | Resultado |
|---|---|
| `fmt.Println(5)` | `5\\n` |
| `fmt.Println(2 + 3.5)` | `5.500\\n` |
| `fmt.Println(3.5 + 2.5)` | `6.000\\n` |
| `fmt.Println("a" + "b")` | `ab\\n` |
| `fmt.Println(true, "Hola", len("abc"), substr("Compilador", 0, 4), typeOf(10), now())` | `true Hola 3 Comp int32 2026-04-14 00:00:00\\n` |

## Estado De Hallazgos Corregidos

### Hallazgo 1 corregido: `len(string)` con valor por defecto
Prueba auditada corregida:

```go
func main() {
    var s string
    fmt.Println(len(s))
}
```

Resultado actual:
- QEMU ejecuta correctamente y produce `0\\n`.

Conclusion:
- El valor por defecto de `string` ya no rompe el runtime.

### Hallazgo 2 corregido: acceso a elementos de arreglo
Prueba auditada corregida:

```go
func main() {
    var arr [3]int32 = [3]int32{1, 2, 3}
    fmt.Println(arr[1])
}
```

Resultado actual:
- La salida real es `2\\n`.

Conclusion:
- El acceso `arr[i]` ya calcula direccion y lee memoria real en ARM64.

### Hallazgo 3 contenido de forma segura: punteros
Prueba auditada:

```go
func set(a *int32) {
    *a = 9
}

func main() {
    x := 5
    set(&x)
    fmt.Println(x)
}
```

Resultado actual:
- El pipeline ya no ejecuta el binario.
- Se reporta la limitacion: `Los punteros se mantienen validados semanticamente, pero la ejecución ARM64 de punteros esta bloqueada hasta completar soporte correcto.`

Interpretacion:
- El soporte de punteros sigue siendo parcial.
- Sin embargo, el comportamiento incorrecto ya no se permite en tiempo de ejecucion.
- Esto evita resultados falsos y deja la limitacion explicitamente documentada.

## Mejoras Recomendadas

1. Completar el modelo de punteros para lectura y escritura por referencia en ARM64.
2. Reemplazar buffers fijos de strings por un modelo de heap mas robusto.
3. Extender arreglos mas alla del acceso lineal simple a multidimensionalidad completa en runtime.
4. Ampliar la suite de pruebas automatizadas de `Proyecto2` con casos de arreglos, punteros y valores por defecto.

## Conclusion Final

### Estado global
El proyecto **cumple funcionalmente los puntos criticos auditados del PDF** y evita
comportamientos incorrectos en tiempo de ejecucion.

### Estado real
Cumple de forma fuerte en:
- arquitectura general del compilador
- integracion con ANTLR
- analisis semantico
- generacion de ASM ARM64
- ensamblado, enlace y ejecucion real con QEMU
- GUI y reportes
- soporte real de `int32`, `bool`, `string` literal y `float32`

Permanece **parcial** en:
- punteros en ejecucion real completa
- manejo de memoria/heap completo
- arreglos avanzados mas alla del acceso lineal simple

### Juicio final
`Proyecto2` ya es un compilador funcional, demostrable y seguro en los puntos criticos
auditados. Cuando una capacidad aun no esta completa, el sistema ya no produce resultados
incorrectos: la bloquea y la documenta.
