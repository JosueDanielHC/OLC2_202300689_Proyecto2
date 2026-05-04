# Manual de usuario — Compilador Golampi (Proyecto 2)

## 1. Descripción del sistema

Esta aplicación es un **compilador web** para el lenguaje académico **Golampi** (sintaxis inspirada en Go). Permite:

- escribir o cargar código fuente;
- analizarlo léxica, sintáctica y semánticamente;
- generar **ensamblador ARM64**;
- ensamblar, enlazar y **ejecutar** el binario con **QEMU** (cuando el toolchain local está disponible);
- revisar errores, tabla de símbolos, metadatos de generación y salida del programa.

El intérprete original del curso (**Proyecto 1**) sigue disponible en la misma raíz del repositorio para consulta o comparación; el flujo del Proyecto 2 usa la misma gramática ANTLR pero añade compilación a ARM64.

## 2. Requisitos

| Requisito | Detalle |
|-----------|---------|
| PHP | 8.0 o superior |
| Composer | Para instalar dependencias PHP del Proyecto 1 (runtime ANTLR) |
| Navegador | Cualquier navegador moderno |
| Espacio en disco | El sysroot del toolchain embebido en `Proyecto2/toolchain/root` ocupa del orden de ~130 MiB |

En Linux, si no usa el toolchain embebido, pueden instalarse paquetes equivalentes (`qemu-user-static`, `binutils-aarch64-linux-gnu`, etc.); el programa intenta primero binarios bajo `Proyecto2/toolchain/root/usr/bin/`.

## 3. Instalación

Desde la **raíz** del repositorio clonado:

```bash
cd Proyecto1/backend && composer install && cd ../..
cd Proyecto2/backend && composer install && cd ../..
```

No suba `vendor/` a Git: se regenera con `composer install` en cada máquina.

## 4. Cómo ejecutar el proyecto

1. Abrir una terminal en la raíz del repositorio.
2. Iniciar el servidor HTTP integrado de PHP (sirve Proyecto 1 y Proyecto 2):

   ```bash
   php -S 0.0.0.0:8000
   ```

3. Abrir en el navegador la interfaz del **Proyecto 2**:

   **http://localhost:8000/Proyecto2/frontend/index.html**

Opcional — interfaz del **Proyecto 1** (intérprete):

**http://localhost:8000/Proyecto1/frontend/index.html**

## 5. Uso de la interfaz (Proyecto 2)

| Control | Función |
|---------|---------|
| Nuevo | Limpia el editor y los paneles de resultados. |
| Cargar archivo | Abre un archivo de texto con código Golampi. |
| Guardar código | Descarga el contenido del editor como archivo. |
| Compilar | Envía el código a `compile.php`, ejecuta todo el pipeline y muestra resultados. |

Tras compilar podrá ver:

- **Consola de diagnósticos**: errores léxicos, sintácticos o semánticos en tabla.
- **Ensamblador generado**: texto ARM64.
- **Ejecución**: salida estándar y errores del binario bajo QEMU (si aplica).
- **Tabla de símbolos** y enlaces de descarga de reportes si la implementación los expone en JSON.

La URL de la API usada por el frontend es, por defecto:

`/Proyecto2/backend/public/compile.php` (mismo origen que la página).

## 6. Ejemplos de uso

Ejemplo mínimo (definir `main` y imprimir):

```go
package main

import "fmt"

func main() {
    fmt.Println("Hola desde Golampi")
}
```

Pegar en el editor, pulsar **Compilar** y revisar ensamblador y salida.

En la carpeta `tests/` hay archivos de ejemplo (`archivo1_basico.go`, etc.) que puede cargar con **Cargar archivo**.

## 7. Entradas y salidas

### Entradas

- **Código fuente** Golampi (texto UTF-8) enviado en el cuerpo JSON del POST a `compile.php`, campo `code`.

### Salidas (respuesta JSON resumida)

| Campo | Significado |
|-------|-------------|
| `ok` | Si el proceso terminó sin errores bloqueantes esperados. |
| `asm` | Cadena con el programa en ensamblador ARM64 generado. |
| `errors` | Lista estructurada de diagnósticos. |
| `execution` | Información de ejecución (stdout/stderr, éxito, mensajes). |
| `symbolTable` | Filas de símbolos para la vista en cliente. |
| `reportErrors` / `reportSymbols` | Texto o rutas de reportes descargables. |

Los archivos `program.s`, `program.o` y el binario `program` se escriben bajo `Proyecto2/output/asm/` durante la compilación (los binarios intermedios pueden ignorarse en Git según `.gitignore`).

## 8. Documentación adicional

- [Manual técnico](MANUAL_TECNICO.md) — arquitectura, módulos y decisiones de diseño.
- [Explicación del proyecto (Proyecto 2)](../Proyecto2/docs/EXPLICACION_PROYECTO.md) — flujo detallado en el repositorio.
