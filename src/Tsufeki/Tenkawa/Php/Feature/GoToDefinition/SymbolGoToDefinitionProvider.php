<?php declare(strict_types=1);

namespace Tsufeki\Tenkawa\Php\Feature\GoToDefinition;

use Tsufeki\Tenkawa\Php\Feature\Symbol;
use Tsufeki\Tenkawa\Php\Feature\SymbolExtractor;
use Tsufeki\Tenkawa\Php\Feature\SymbolReflection;
use Tsufeki\Tenkawa\Php\Reflection\Element\Element;
use Tsufeki\Tenkawa\Server\Document\Document;
use Tsufeki\Tenkawa\Server\Feature\Common\Position;
use Tsufeki\Tenkawa\Server\Feature\GoToDefinition\GoToDefinitionProvider;

class SymbolGoToDefinitionProvider implements GoToDefinitionProvider
{
    /**
     * @var SymbolExtractor
     */
    private $symbolExtractor;

    /**
     * @var SymbolReflection
     */
    private $symbolReflection;

    public function __construct(
        SymbolExtractor $symbolExtractor,
        SymbolReflection $symbolReflection
    ) {
        $this->symbolExtractor = $symbolExtractor;
        $this->symbolReflection = $symbolReflection;
    }

    public function getLocations(Document $document, Position $position): \Generator
    {
        if ($document->getLanguage() !== 'php') {
            return [];
        }

        /** @var Symbol|null */
        $symbol = yield $this->symbolExtractor->getSymbolAt($document, $position);
        if ($symbol === null) {
            return [];
        }

        /** @var Element[] $elements */
        $elements = yield $this->symbolReflection->getReflectionFromSymbol($symbol);

        return array_values(array_filter(array_map(function (Element $element) {
            return $element->location;
        }, $elements)));
    }
}
