const editor = document.getElementById('editor');
const consoleEl = document.getElementById('console');
const executionConsoleEl = document.getElementById('executionConsole');
const statusConsoleEl = document.getElementById('statusConsole');
const fileInput = document.getElementById('fileInput');

let lastResult = {
  asm: '',
  errors: [],
  reportErrors: '',
  reportSymbols: '',
  execution: null,
  codegen: {},
};

function apiUrl() {
  return `${window.location.origin}/Proyecto2/backend/public/compile.php`;
}

function setConsole(text, isError = false) {
  consoleEl.classList.toggle('error', isError);
  consoleEl.classList.remove('success');
  if (Array.isArray(text)) {
    let html = '<table><thead><tr><th>#</th><th>Tipo</th><th>Descripción</th><th>Línea</th><th>Columna</th></tr></thead><tbody>';
    text.forEach((item, index) => {
      html += `<tr><td>${index + 1}</td><td>${escapeHtml(item.type || '')}</td><td>${escapeHtml(item.message || '')}</td><td>${item.line ?? ''}</td><td>${item.column ?? ''}</td></tr>`;
    });
    html += '</tbody></table>';
    consoleEl.innerHTML = html;
    return;
  }

  consoleEl.textContent = text || '';
}

function setExecutionConsole(text, mode = 'plain') {
  executionConsoleEl.classList.remove('error', 'success');
  if (mode === 'error') {
    executionConsoleEl.classList.add('error');
  } else if (mode === 'success') {
    executionConsoleEl.classList.add('success');
  }
  executionConsoleEl.textContent = text || '';
}

function setStatusConsole(text, isError = false) {
  statusConsoleEl.classList.toggle('error', isError);
  statusConsoleEl.classList.remove('success');
  if (!isError) {
    statusConsoleEl.classList.add('success');
  }
  statusConsoleEl.textContent = text || '';
}

function escapeHtml(value) {
  const node = document.createElement('div');
  node.textContent = value;
  return node.innerHTML;
}

function download(filename, content, type = 'text/plain;charset=utf-8') {
  const blob = new Blob([content], { type });
  const link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = filename;
  link.click();
  URL.revokeObjectURL(link.href);
}

document.getElementById('btnNew').onclick = () => {
  editor.value = '';
  setConsole('');
  setExecutionConsole('');
  setStatusConsole('');
};

document.getElementById('btnLoad').onclick = () => fileInput.click();

fileInput.onchange = () => {
  const file = fileInput.files[0];
  if (!file) {
    return;
  }

  const reader = new FileReader();
  reader.onload = () => {
    editor.value = String(reader.result || '');
  };
  reader.readAsText(file);
};

document.getElementById('btnSave').onclick = () => {
  download('programa.golampi', editor.value);
};

document.getElementById('btnClearConsole').onclick = () => {
  setConsole('');
  setExecutionConsole('');
  setStatusConsole('');
};

document.getElementById('btnCompile').onclick = async () => {
  setConsole('Compilando...');
  setExecutionConsole('');
  setStatusConsole('Generando parser, semántica y ARM64...');

  try {
    const response = await fetch(apiUrl(), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ code: editor.value }),
    });

    const data = await response.json();
    lastResult = data;

    if (data.errors && data.errors.length > 0) {
      setConsole(data.errors, true);
      setExecutionConsole('La ejecución no se intentó porque hubo errores de compilación.', 'error');
      setStatusConsole('Compilación fallida con errores.', true);
      return;
    }

    setConsole(data.asm || '(sin ensamblador generado)');

    const limitations = (data.codegen && Array.isArray(data.codegen.limitations))
      ? data.codegen.limitations
      : [];

    if (data.execution && data.execution.available) {
      const stdout = data.execution.stdout || '(sin salida estándar)';
      const stderr = data.execution.stderr || '';
      const mode = data.execution.ok ? 'success' : 'error';
      setExecutionConsole(stderr ? `${stdout}\n\n[stderr]\n${stderr}` : stdout, mode);
    } else if (data.execution && data.execution.message) {
      setExecutionConsole(data.execution.message, 'error');
    } else {
      setExecutionConsole('No se ejecutó el binario ARM64.', 'error');
    }

    const statusLines = [];
    statusLines.push(data.ok ? 'Compilación exitosa.' : 'Compilación incompleta.');
    if (data.execution && data.execution.available) {
      statusLines.push('Validación ARM64 ejecutada con QEMU.');
    } else if (data.execution && data.execution.message) {
      statusLines.push(`Ejecución ARM64: ${data.execution.message}`);
    }
    if (limitations.length > 0) {
      statusLines.push('');
      statusLines.push('Limitaciones del codegen:');
      limitations.forEach((item) => statusLines.push(`- ${item}`));
    }
    setStatusConsole(statusLines.join('\n'), false);
  } catch (error) {
    setConsole(`Error de conexión: ${error.message}`, true);
    setExecutionConsole('', 'plain');
    setStatusConsole('Fallo la comunicación con el backend.', true);
  }
};

document.getElementById('btnDownloadErrors').onclick = () => {
  download('errores.txt', lastResult.reportErrors || '');
};

document.getElementById('btnDownloadSymbols').onclick = () => {
  download('tabla_simbolos.txt', lastResult.reportSymbols || '');
};

document.getElementById('btnDownloadAsm').onclick = () => {
  download('program.s', lastResult.asm || '');
};

document.getElementById('btnDownloadExecution').onclick = () => {
  const execution = lastResult.execution || {};
  const stdout = execution.stdout || '';
  const stderr = execution.stderr || '';
  const text = stdout + (stderr ? `\n\n[stderr]\n${stderr}` : '');
  download('ejecucion.txt', text);
};
