<?php

declare(strict_types=1);

namespace Proyecto2\Support;

final class Paths
{
    public static function backendRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function projectRoot(): string
    {
        return dirname(self::backendRoot(), 1);
    }

    public static function repositoryRoot(): string
    {
        return dirname(self::projectRoot(), 1);
    }

    public static function legacyBackendRoot(): string
    {
        return self::repositoryRoot() . '/Proyecto1/backend';
    }

    public static function outputAsmPath(): string
    {
        return self::projectRoot() . '/output/asm/program.s';
    }

    public static function localToolchainRoot(): string
    {
        return self::projectRoot() . '/toolchain/root';
    }

    public static function localToolchainBin(): string
    {
        return self::localToolchainRoot() . '/usr/bin';
    }

    public static function localToolchainHostLib(): string
    {
        return self::localToolchainRoot() . '/usr/lib/x86_64-linux-gnu';
    }

    public static function localToolchainSysroot(): string
    {
        return self::localToolchainRoot() . '/usr/aarch64-linux-gnu';
    }

    public static function ensureOutputDirectory(): void
    {
        $dir = dirname(self::outputAsmPath());
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }
}
