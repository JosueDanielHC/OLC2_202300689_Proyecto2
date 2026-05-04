<?php

declare(strict_types=1);

namespace Proyecto2\Codegen;

use Proyecto2\Support\Paths;

final class AsmRunner
{
    public function run(string $assemblyPath): ExecutionResult
    {
        $assembler = $this->resolveTool('aarch64-linux-gnu-as');
        $linker = $this->resolveTool('aarch64-linux-gnu-ld');
        $qemu = $this->resolveTool('qemu-aarch64');

        if ($assembler === null || $linker === null || $qemu === null) {
            return new ExecutionResult(
                false,
                false,
                '',
                '',
                null,
                null,
                'Toolchain ARM64 no disponible en el entorno actual.'
            );
        }

        $outputDir = dirname($assemblyPath);
        $objectPath = $outputDir . '/program.o';
        $binaryPath = $outputDir . '/program';
        $hostEnv = $this->hostEnvironment();

        [$okAssemble, $assembleStdout, $assembleStderr] = $this->runCommand([
            $assembler,
            $assemblyPath,
            '-o',
            $objectPath,
        ], $hostEnv);

        if (!$okAssemble) {
            return new ExecutionResult(true, false, $assembleStdout, $assembleStderr, $objectPath, null, 'Falló el ensamblado ARM64.');
        }

        [$okLink, $linkStdout, $linkStderr] = $this->runCommand([
            $linker,
            $objectPath,
            '-o',
            $binaryPath,
        ], $hostEnv);

        if (!$okLink) {
            return new ExecutionResult(true, false, $linkStdout, $linkStderr, $objectPath, $binaryPath, 'Falló el enlace ARM64.');
        }

        [$okRun, $runStdout, $runStderr] = $this->runCommand([
            $qemu,
            '-L',
            Paths::localToolchainSysroot(),
            $binaryPath,
        ], $hostEnv);

        return new ExecutionResult(
            true,
            $okRun,
            $runStdout,
            $runStderr,
            $objectPath,
            $binaryPath,
            $okRun ? 'Ejecución completada.' : 'La ejecución en QEMU terminó con error.'
        );
    }

    private function resolveTool(string $command): ?string
    {
        $localCandidate = Paths::localToolchainBin() . '/' . $command;
        if (is_file($localCandidate) && is_executable($localCandidate)) {
            return $localCandidate;
        }

        $path = trim((string) shell_exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null'));
        return $path !== '' ? $path : null;
    }

    /**
     * @return array<string, string>
     */
    private function hostEnvironment(): array
    {
        $env = $_ENV;
        $hostLib = Paths::localToolchainHostLib();
        if (is_dir($hostLib)) {
            $existing = $env['LD_LIBRARY_PATH'] ?? getenv('LD_LIBRARY_PATH') ?: '';
            $env['LD_LIBRARY_PATH'] = $existing !== '' ? $hostLib . ':' . $existing : $hostLib;
        }

        return $env;
    }

    /**
     * @param list<string> $parts
     * @return array{0:bool,1:string,2:string}
     */
    private function runCommand(array $parts, ?array $env = null): array
    {
        $command = implode(' ', array_map('escapeshellarg', $parts));
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, Paths::projectRoot(), $env);
        if (!is_resource($process)) {
            return [false, '', 'No se pudo iniciar el proceso.'];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        return [$exitCode === 0, $stdout, $stderr];
    }
}
