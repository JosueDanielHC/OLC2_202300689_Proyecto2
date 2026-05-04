<?php

declare(strict_types=1);

namespace Proyecto2\Codegen;

use Proyecto2\Semantic\FunctionSymbol;

final class StackFrameLayout
{
    public function frameSize(FunctionSymbol $function): int
    {
        $size = max(16, $function->frameSize);
        return (int) (ceil($size / 16) * 16);
    }
}
