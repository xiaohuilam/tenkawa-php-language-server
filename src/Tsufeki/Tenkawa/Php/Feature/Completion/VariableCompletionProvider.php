<?php declare(strict_types=1);

namespace Tsufeki\Tenkawa\Php\Feature\Completion;

use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use Tsufeki\Tenkawa\Php\Feature\MemberSymbolExtractor;
use Tsufeki\Tenkawa\Php\Feature\NodeFinder;
use Tsufeki\Tenkawa\Php\Parser\Ast;
use Tsufeki\Tenkawa\Php\Parser\Parser;
use Tsufeki\Tenkawa\Php\TypeInference\Type;
use Tsufeki\Tenkawa\Php\TypeInference\TypeInference;
use Tsufeki\Tenkawa\Server\Document\Document;
use Tsufeki\Tenkawa\Server\Feature\Common\Position;
use Tsufeki\Tenkawa\Server\Feature\Common\TextEdit;
use Tsufeki\Tenkawa\Server\Feature\Completion\CompletionContext;
use Tsufeki\Tenkawa\Server\Feature\Completion\CompletionItem;
use Tsufeki\Tenkawa\Server\Feature\Completion\CompletionItemKind;
use Tsufeki\Tenkawa\Server\Feature\Completion\CompletionList;
use Tsufeki\Tenkawa\Server\Feature\Completion\CompletionProvider;
use Tsufeki\Tenkawa\Server\Utils\PositionUtils;

class VariableCompletionProvider implements CompletionProvider
{
    /**
     * @var Parser
     */
    private $parser;

    /**
     * @var NodeFinder
     */
    private $nodeFinder;

    /**
     * @var TypeInference
     */
    private $typeInference;

    /**
     * @var MemberSymbolExtractor
     */
    private $memberSymbolExtractor;

    public function __construct(
        Parser $parser,
        TypeInference $typeInference,
        MemberSymbolExtractor $memberSymbolExtractor,
        NodeFinder $nodeFinder
    ) {
        $this->parser = $parser;
        $this->typeInference = $typeInference;
        $this->memberSymbolExtractor = $memberSymbolExtractor;
        $this->nodeFinder = $nodeFinder;
    }

    public function getTriggerCharacters(): array
    {
        return ['$'];
    }

    public function getCompletions(
        Document $document,
        Position $position,
        ?CompletionContext $context
    ): \Generator {
        $completions = new CompletionList();

        if ($document->getLanguage() !== 'php') {
            return $completions;
        }

        /** @var (Node|Comment)[] $nodes */
        $nodes = yield $this->nodeFinder->getNodePath($document, $position);
        if (count($nodes) >= 1 && $nodes[0] instanceof Expr\Error) {
            array_shift($nodes);
        }
        if (count($nodes) < 1 || !($nodes[0] instanceof Expr\Variable)) {
            return $completions;
        }
        $range = PositionUtils::rangeFromNodeAttrs($nodes[0]->getAttributes(), $document);
        if ($position == $range->start) {
            return $completions;
        }

        yield $this->typeInference->infer($document);
        /** @var array<string,Type|null> $variables */
        $variables = yield $this->getVariables($nodes, $document);

        $variableUnderCursor = is_string($nodes[0]->name) ? $nodes[0]->name : null;

        foreach ($variables as $name => $type) {
            if ($name !== $variableUnderCursor) {
                $item = new CompletionItem();
                $item->label = '$' . $name;
                $item->kind = CompletionItemKind::VARIABLE;
                $item->detail = $type ? (string)$type : null;
                $item->filterText = $name;
                $item->sortText = $name;
                $item->insertText = $name;
                $item->textEdit = new TextEdit();
                $item->textEdit->range = clone $range;
                $item->textEdit->range->start = clone $item->textEdit->range->start;
                $item->textEdit->range->start->character++;
                $item->textEdit->newText = $name;

                $completions->items[] = $item;
            }
        }

        return $completions;
    }

    /**
     * @param (Node|Comment)[] $nodes
     *
     * @resolve array<string,Type|null>
     */
    private function getVariables(array $nodes, Document $document): \Generator
    {
        $statements = null;
        $variables = [];

        foreach ($nodes as $node) {
            if ($node instanceof Stmt\ClassLike) {
                $statements = [];
                break;
            }
            if ($node instanceof FunctionLike) {
                $statements = $node->getStmts() ?: [];
                foreach ($node->getParams() as $param) {
                    if ($name = $this->getVariableName($param->var)) {
                        $variables[$name] = null; // TODO: type
                    }
                }

                if ($this->memberSymbolExtractor->isInObjectContext($nodes)) {
                    $variables['this'] = null; // TODO: type
                }

                if ($node instanceof Expr\Closure) {
                    foreach ($node->uses as $use) {
                        if ($name = $this->getVariableName($use->var)) {
                            $variables[$name] = null; // TODO: type
                        }
                    }
                }
                break;
            }
        }

        if ($statements === null) {
            /** @var Ast $ast */
            $ast = yield $this->parser->parse($document);
            $statements = $ast->nodes;
        }

        $visitor = new VariableGatheringVisitor($variables);
        $nodeTraverser = new NodeTraverser();
        $nodeTraverser->addVisitor($visitor);
        $nodeTraverser->traverse($statements);
        $variables = $visitor->getVariables();

        return $variables;
    }

    /**
     * @param Expr\Variable|Expr\Error|null $var
     */
    private function getVariableName(?Node $var): ?string
    {
        if ($var instanceof Expr\Variable && is_string($var->name)) {
            return $var->name;
        }

        return null;
    }
}
