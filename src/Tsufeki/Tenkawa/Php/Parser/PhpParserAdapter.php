<?php declare(strict_types=1);

namespace Tsufeki\Tenkawa\Php\Parser;

use PhpLenientParser\LenientParser;
use PhpLenientParser\LenientParserFactory;
use PhpParser\ErrorHandler;
use PhpParser\Lexer;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use Tsufeki\Tenkawa\Server\Document\Document;

class PhpParserAdapter implements Parser
{
    /**
     * @var LenientParser
     */
    private $parser;

    /**
     * @var Lexer
     */
    private $lexer;

    public function __construct()
    {
        $this->lexer = new Lexer\Emulative(['usedAttributes' => [
            'comments',
            'startLine', 'endLine',
            'startFilePos', 'endFilePos',
            'startTokenPos', 'endTokenPos',
        ]]);

        $this->parser = (new LenientParserFactory())->create(LenientParserFactory::ONLY_PHP7, $this->lexer);
    }

    /**
     * @resolve Node[]
     */
    public function parse(Document $document): \Generator
    {
        if ($document->getLanguage() !== 'php') {
            throw new \LogicException('Can only parse PHP documents');
        }

        $ast = $document->get('parser.ast');
        if ($ast) {
            return $ast;
        }

        $ast = new Ast();
        $errorHandler = new ErrorHandler\Collecting();
        $ast->nodes = $this->parser->parse($document->getText(), $errorHandler) ?? [];
        $ast->tokens = $this->lexer->getTokens();

        $nodeTraverser = new NodeTraverser();
        $nodeTraverser->addVisitor(new NameResolver($errorHandler, [
            'preserveOriginalNames' => true,
        ]));
        $nodeTraverser->traverse($ast->nodes);

        $ast->errors = $errorHandler->getErrors();
        $document->set('parser.ast', $ast);

        return $ast;
        yield;
    }

    public function parseExpr(string $expr): \Generator
    {
        $errorHandler = new ErrorHandler\Collecting();
        $node = $this->parser->parse("<?php $expr;", $errorHandler)[0] ?? null;

        if ($node instanceof Stmt\Expression) {
            $node = $node->expr;
        }

        return $node instanceof Expr ? $node : new Expr\Error();
        yield;
    }
}
