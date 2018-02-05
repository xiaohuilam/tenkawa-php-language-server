<?php declare(strict_types=1);

namespace Tsufeki\Tenkawa\Php\Language;

use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt;
use Tsufeki\Tenkawa\Php\Reflection\Element\Element;
use Tsufeki\Tenkawa\Php\Reflection\ReflectionProvider;
use Tsufeki\Tenkawa\Server\Document\Document;

class GlobalsHelper
{
    const CLASS_REFERENCING_NODES = [
        Expr\ClassConstFetch::class => true,
        Expr\Closure::class => true,
        Expr\Instanceof_::class => true,
        Expr\New_::class => true,
        Expr\StaticCall::class => true,
        Expr\StaticPropertyFetch::class => true,
        Stmt\Catch_::class => true,
        Stmt\ClassMethod::class => true,
        Stmt\Class_::class => true,
        Stmt\Function_::class => true,
        Stmt\Interface_::class => true,
        Stmt\TraitUse::class => true,
        Stmt\TraitUseAdaptation\Alias::class => true,
        Stmt\TraitUseAdaptation\Precedence::class => true,
        NullableType::class => true,
        Param::class => true,
    ];

    const FUNCTION_REFERENCING_NODES = [
        Expr\FuncCall::class => true,
    ];

    const CONST_REFERENCING_NODES = [
        Expr\ConstFetch::class => true,
    ];

    /**
     * @var ReflectionProvider
     */
    private $reflectionProvider;

    public function __construct(ReflectionProvider $reflectionProvider)
    {
        $this->reflectionProvider = $reflectionProvider;
    }

    /**
     * @return string|null
     */
    public function getReferencedClass(Name $name, Node $parentNode = null, Node $grandparentNode = null)
    {
        if ($parentNode !== null && isset(self::CLASS_REFERENCING_NODES[get_class($parentNode)])) {
            return '\\' . $name->toString();
        }

        return $this->getReferencedSymbolFromUse($name, $parentNode, $grandparentNode, Stmt\Use_::TYPE_NORMAL);
    }

    /**
     * @return string|null
     */
    public function getReferencedFunction(Name $name, Node $parentNode = null, Node $grandparentNode = null)
    {
        // TODO: namespaced vs global resolve of unqualified names
        if ($parentNode !== null && isset(self::FUNCTION_REFERENCING_NODES[get_class($parentNode)])) {
            return '\\' . $name->toString();
        }

        return $this->getReferencedSymbolFromUse($name, $parentNode, $grandparentNode, Stmt\Use_::TYPE_FUNCTION);
    }

    /**
     * @return string|null
     */
    public function getReferencedConst(Name $name, Node $parentNode = null, Node $grandparentNode = null)
    {
        if ($parentNode !== null && isset(self::CONST_REFERENCING_NODES[get_class($parentNode)])) {
            return '\\' . $name->toString();
        }

        return $this->getReferencedSymbolFromUse($name, $parentNode, $grandparentNode, Stmt\Use_::TYPE_CONSTANT);
    }

    /**
     * @return string|null
     */
    private function getReferencedSymbolFromUse(Name $name, Node $parentNode = null, Node $grandparentNode = null, int $type)
    {
        if ($parentNode instanceof Stmt\UseUse) {
            if ($grandparentNode instanceof Stmt\Use_ && $grandparentNode->type === $type) {
                return '\\' . $name->toString();
            }

            if ($grandparentNode instanceof Stmt\GroupUse
                && ($grandparentNode->type === $type || $parentNode->type === $type)
            ) {
                return '\\' . $grandparentNode->prefix->toString() . '\\' . $name->toString();
            }
        }

        return null;
    }

    /**
     * @param (Node|Comment)[] $nodes
     *
     * @resolve Element[]
     */
    public function getReflectionFromNodePath(array $nodes, Document $document): \Generator
    {
        $elements = [];

        if (count($nodes) >= 2 && $nodes[0] instanceof Name) {
            $coroutines = [];
            /** @var Name $name */
            $name = $nodes[0];
            $nodes = array_slice($nodes, 1, 3);

            $className = $this->getReferencedClass($name, ...$nodes);
            if ($className !== null) {
                $coroutines[] = $this->reflectionProvider->getClass($document, $className);
            }

            $functionName = $this->getReferencedFunction($name, ...$nodes);
            if ($functionName !== null) {
                $coroutines[] = $this->reflectionProvider->getFunction($document, $functionName);
            }

            $constName = $this->getReferencedConst($name, ...$nodes);
            if ($constName !== null) {
                $coroutines[] = $this->reflectionProvider->getConst($document, $constName);
            }

            $elements = array_merge(...yield $coroutines);
        }

        return $elements;
    }
}