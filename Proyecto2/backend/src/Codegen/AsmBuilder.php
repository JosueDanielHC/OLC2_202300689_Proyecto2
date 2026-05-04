<?php

declare(strict_types=1);

namespace Proyecto2\Codegen;

final class AsmBuilder
{
    /** @var list<string> */
    private array $data = [];

    /** @var list<string> */
    private array $rodata = [];

    /** @var list<string> */
    private array $bss = [];

    /** @var list<string> */
    private array $text = [];

    public function addData(string $line): void
    {
        $this->data[] = $line;
    }

    public function addRodata(string $line): void
    {
        $this->rodata[] = $line;
    }

    public function addBss(string $line): void
    {
        $this->bss[] = $line;
    }

    public function addText(string $line = ''): void
    {
        $this->text[] = $line;
    }

    public function addComment(string $comment): void
    {
        $this->text[] = '# ' . $comment;
    }

    public function render(): string
    {
        $sections = [];

        if ($this->data !== []) {
            $sections[] = ".section .data\n" . implode("\n", $this->data);
        }

        if ($this->rodata !== []) {
            $sections[] = ".section .rodata\n" . implode("\n", $this->rodata);
        }

        if ($this->bss !== []) {
            $sections[] = ".section .bss\n.align 3\n" . implode("\n", $this->bss);
        }

        $sections[] = ".section .text\n.align 2\n" . implode("\n", $this->text);

        return implode("\n\n", $sections) . "\n";
    }
}
