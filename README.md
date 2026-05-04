# OLC2 — Proyecto 2: compilador Golampi (ARM64)

Repositorio del **Proyecto 2** del curso Organización de Lenguajes y Compiladores 2: compilador del lenguaje **Golampi** hacia **ensamblador ARM64**, con interfaz web, ensamblado, enlace y ejecución mediante **QEMU**, reutilizando el análisis léxico y sintáctico (**ANTLR**) del **Proyecto 1**.

| Campo | Valor |
|-------|--------|
| Autor | Josue Daniel Herrera Cottom |
| Carné | 202300689 |
| Curso | Organización de Lenguajes y Compiladores 2 |

**Repositorio:** https://github.com/JosueDanielHC/OLC2_202300689_Proyecto2.git

---

## Descripción general

El flujo del Proyecto 2 recibe código Golampi, valida semántica con un visitor propio, genera ARM64, escribe `Proyecto2/output/asm/program.s` y, cuando el entorno lo permite, ejecuta el binario y devuelve la salida en JSON a la interfaz.

El **Proyecto 1** (intérprete + gramática + fuentes generadas ANTLR) vive en `Proyecto1/` y no debe duplicarse: el bootstrap de Proyecto 2 enlaza solo lectura contra `Proyecto1/backend/generated/` y `vendor/`.

---

## Estructura del proyecto

```
.
├── Proyecto1/
│   ├── backend/          # Gramática ANTLR, código generado, intérprete, api.php
│   └── frontend/         # Interfaz web del intérprete (Proyecto 1)
├── Proyecto2/
│   ├── backend/          # Compilador: pipeline, semántica, codegen, compile.php
│   ├── frontend/         # Interfaz web del compilador ARM64
│   ├── output/asm/       # Salida program.s y binarios de prueba (parcialmente ignorados)
│   ├── toolchain/        # Sysroot y binarios locales para as/ld/qemu (opcional)
│   └── docs/             # Explicación del proyecto y auditorías
├── docs/                 # Manual de usuario, manual técnico y documentación de entrega
├── tests/                # Ejemplos .go para pruebas manuales
└── README.md
```

---

## Requisitos

- PHP **8.0+**
- [Composer](https://getcomposer.org/) para `Proyecto1/backend` (runtime ANTLR PHP)

---

## Instalación

En la raíz del repositorio:

```bash
cd Proyecto1/backend && composer install && cd ../..
cd Proyecto2/backend && composer install && cd ../..
```

---

## Cómo ejecutar

```bash
php -S 0.0.0.0:8000
```

Abrir en el navegador:

- **Compilador (Proyecto 2):** http://localhost:8000/Proyecto2/frontend/index.html  
- **Intérprete (Proyecto 1):** http://localhost:8000/Proyecto1/frontend/index.html  

El frontend del Proyecto 2 llama a `POST /Proyecto2/backend/public/compile.php` con cuerpo JSON `{ "code": "..." }`.

---

## Documentación

| Documento | Contenido |
|-----------|-----------|
| [Manual de usuario](docs/MANUAL_USUARIO.md) | Requisitos, instalación, ejecución, uso de la GUI, entradas/salidas, ejemplos |
| [Manual técnico](docs/MANUAL_TECNICO.md) | Arquitectura, módulos, flujo, tecnologías, decisiones de diseño |
| [Explicación del proyecto (detalle)](Proyecto2/docs/EXPLICACION_PROYECTO.md) | Flujo paso a paso y diagramas |
| [Documentación técnica histórica (P1)](docs/DOCUMENTACION_TECNICA.md) | Referencia del intérprete y gramática |

---

## Tecnologías

| Componente | Tecnología |
|------------|------------|
| Backend compilador | PHP 8 |
| Parser / lexer | ANTLR 4 (artefactos en Proyecto 1) |
| Frontend | HTML, CSS, JavaScript |
| Ejecución | Ensamblador y linker `aarch64-linux-gnu-*`, `qemu-aarch64` (sistema o `Proyecto2/toolchain/root`) |

---

## Créditos

Proyecto académico USAC — OLC2. Repositorio de entrega: **OLC2_202300689_Proyecto2**.
