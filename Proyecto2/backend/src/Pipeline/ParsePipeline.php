<?php

declare(strict_types=1);

namespace Proyecto2\Pipeline;

use Antlr\Antlr4\Runtime\CommonTokenStream;
use Antlr\Antlr4\Runtime\InputStream;
use Proyecto2\Diagnostics\DiagnosticBag;
use Proyecto2\Diagnostics\LexerErrorListener;
use Proyecto2\Diagnostics\SyntaxErrorListener;

final class ParsePipeline
{
    public function __construct(private readonly SourceNormalizer $normalizer = new SourceNormalizer())
    {
    }

    public function parse(string $source): ParseResult
    {
        $diagnostics = new DiagnosticBag();
        $normalized = $this->normalizer->normalize($source);

        $input = InputStream::fromString($normalized);
        $lexer = new \GolampiLexer($input);
        $lexer->removeErrorListeners();
        $lexer->addErrorListener(new LexerErrorListener($diagnostics));

        $tokens = new CommonTokenStream($lexer);
        $parser = new \GolampiParser($tokens);
        $parser->removeErrorListeners();
        $parser->addErrorListener(new SyntaxErrorListener($diagnostics));

        $tree = $parser->program();

        return new ParseResult($source, $normalized, $tree, $tokens, $diagnostics);
    }
}
