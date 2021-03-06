<?php declare(strict_types=1);

namespace Tsufeki\Tenkawa\Server\Index;

use Psr\Log\LoggerInterface;
use Recoil\Recoil;
use Tsufeki\Tenkawa\Server\Document\Document;
use Tsufeki\Tenkawa\Server\Document\DocumentStore;
use Tsufeki\Tenkawa\Server\Document\Project;
use Tsufeki\Tenkawa\Server\Event\Document\OnChange;
use Tsufeki\Tenkawa\Server\Event\Document\OnClose;
use Tsufeki\Tenkawa\Server\Event\Document\OnOpen;
use Tsufeki\Tenkawa\Server\Event\Document\OnProjectOpen;
use Tsufeki\Tenkawa\Server\Event\EventDispatcher;
use Tsufeki\Tenkawa\Server\Event\OnFileChange;
use Tsufeki\Tenkawa\Server\Event\OnIndexingFinished;
use Tsufeki\Tenkawa\Server\Feature\ProgressNotification\ProgressGroup;
use Tsufeki\Tenkawa\Server\Feature\ProgressNotification\ProgressNotificationFeature;
use Tsufeki\Tenkawa\Server\Index\Storage\ChainedStorage;
use Tsufeki\Tenkawa\Server\Index\Storage\IndexStorage;
use Tsufeki\Tenkawa\Server\Index\Storage\MergedStorage;
use Tsufeki\Tenkawa\Server\Index\Storage\WritableIndexStorage;
use Tsufeki\Tenkawa\Server\Io\FileLister\FileFilter;
use Tsufeki\Tenkawa\Server\Io\FileLister\FileLister;
use Tsufeki\Tenkawa\Server\Io\FileReader;
use Tsufeki\Tenkawa\Server\Uri;
use Tsufeki\Tenkawa\Server\Utils\PriorityKernel\Priority;
use Tsufeki\Tenkawa\Server\Utils\Stopwatch;

class Indexer implements OnOpen, OnChange, OnClose, OnProjectOpen, OnFileChange
{
    /**
     * @var IndexDataProvider[]
     */
    private $indexDataProviders;

    /**
     * @var GlobalIndexer[]
     */
    private $globalIndexers;

    /**
     * @var IndexStorageFactory
     */
    private $indexStorageFactory;

    /**
     * @var DocumentStore
     */
    private $documentStore;

    /**
     * @var FileReader
     */
    private $fileReader;

    /**
     * @var FileLister
     */
    private $fileLister;

    /**
     * @var FileFilter[]
     */
    private $fileFilters;

    /**
     * @var FileFilterFactory[]
     */
    private $fileFilterFactories;

    /**
     * @var IndexStorage|null
     */
    private $globalIndex;

    /**
     * @var EventDispatcher
     */
    private $eventDispatcher;

    /**
     * @var ProgressGroup
     */
    private $progress;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var string
     */
    private $indexDataVersion;

    /**
     * @var bool
     */
    private $buildingGlobalIndex = false;

    /**
     * @param IndexDataProvider[] $indexDataProviders
     * @param GlobalIndexer[]     $globalIndexers
     * @param FileFilter[]        $fileFilters
     * @param FileFilterFactory[] $fileFilterFactories
     */
    public function __construct(
        array $indexDataProviders,
        array $globalIndexers,
        IndexStorageFactory $indexStorageFactory,
        DocumentStore $documentStore,
        FileReader $fileReader,
        FileLister $fileLister,
        array $fileFilters,
        array $fileFilterFactories,
        EventDispatcher $eventDispatcher,
        ProgressNotificationFeature $progressNotificationFeature,
        LoggerInterface $logger
    ) {
        $this->indexDataProviders = $indexDataProviders;
        $this->globalIndexers = $globalIndexers;
        $this->indexStorageFactory = $indexStorageFactory;
        $this->documentStore = $documentStore;
        $this->fileReader = $fileReader;
        $this->fileLister = $fileLister;
        $this->fileFilters = $fileFilters;
        $this->fileFilterFactories = $fileFilterFactories;
        $this->eventDispatcher = $eventDispatcher;
        $this->progress = $progressNotificationFeature->create();
        $this->logger = $logger;

        $versions = array_map(function (IndexDataProvider $provider) {
            return get_class($provider) . '=' . $provider->getVersion();
        }, $this->indexDataProviders);
        sort($versions);
        $this->indexDataVersion = implode(';', $versions);
    }

    private function indexDocument(
        Document $document,
        WritableIndexStorage $indexStorage,
        ?int $timestamp,
        ?string $origin
    ): \Generator {
        $entries = [];

        $fileEntry = new IndexEntry();
        $fileEntry->sourceUri = $document->getUri();
        $fileEntry->category = 'file';
        $fileEntry->key = '';
        $entries[] = $fileEntry;

        foreach ($this->indexDataProviders as $provider) {
            $entries = array_merge($entries, yield $provider->getEntries($document, $origin));
        }

        yield $indexStorage->replaceFile($document->getUri(), $entries, $timestamp);
    }

    private function clearDocument(Uri $uri, WritableIndexStorage $indexStorage): \Generator
    {
        yield $indexStorage->replaceFile($uri, [], null);
    }

    public function indexProject(
        Project $project,
        WritableIndexStorage $indexStorage,
        ?Uri $subpath,
        ?string $origin
    ): \Generator {
        $rootUri = $project->getRootUri();
        $subpath = $subpath ?? $rootUri;
        if ($rootUri->getScheme() !== 'file'
            || empty($this->indexDataProviders)
            || (!$rootUri->equals($subpath) && !$rootUri->isParentOf($subpath))) {
            return;
        }

        $fileFilters = array_merge(
            $this->fileFilters,
            yield array_map(function (FileFilterFactory $factory) use ($project) {
                return $factory->getFilter($project);
            }, $this->fileFilterFactories)
        );

        if (empty($fileFilters)) {
            return;
        }

        $stopwatch = new Stopwatch();
        // $this->logger->debug("Indexing started: $subpath");
        $progress = $this->progress->get();

        $indexedFiles = yield $indexStorage->getFileTimestamps($subpath);
        $processedFilesCount = 0;

        foreach (yield $this->fileLister->list($subpath, $fileFilters, $rootUri) as $uriString => [$language, $timestamp]) {
            yield;
            if (array_key_exists($uriString, $indexedFiles) && $indexedFiles[$uriString] === $timestamp) {
                unset($indexedFiles[$uriString]);
                continue;
            }

            if ($stopwatch->getSeconds() >= 2.0) {
                $progress->set('Indexing files...');
            }

            try {
                unset($indexedFiles[$uriString]);
                $uri = Uri::fromString($uriString);
                $text = yield $this->fileReader->read($uri);
                $document = yield $this->documentStore->load($uri, $language, $text);
                $processedFilesCount++;

                yield $this->indexDocument($document, $indexStorage, $timestamp, $origin);
            } catch (\Throwable $e) {
                $this->logger->warning("Can't index $uriString", ['exception' => $e]);
            }
        }

        foreach ($indexedFiles as $uriString => $timestamp) {
            yield;
            $uri = Uri::fromString($uriString);
            yield $this->clearDocument($uri, $indexStorage);
            $processedFilesCount++;
        }

        $progress->done();

        if ($processedFilesCount > 0) {
            if (!$this->buildingGlobalIndex) {
                yield $this->eventDispatcher->dispatch(OnIndexingFinished::class);
            }
            if ($stopwatch->getSeconds() >= 10.0) {
                $this->logger->info("Indexing finished: $subpath [$processedFilesCount files, $stopwatch]");
            }
        }
    }

    public function buildGlobalIndex(): \Generator
    {
        $this->buildingGlobalIndex = true;

        yield array_map(function (GlobalIndexer $globalIndexer) {
            return $globalIndexer->buildIndex($this);
        }, $this->globalIndexers);

        $this->buildingGlobalIndex = false;
    }

    private function getGlobalIndex(): \Generator
    {
        if ($this->globalIndex === null) {
            $this->globalIndex = new MergedStorage(yield array_map(function (GlobalIndexer $globalIndexer) {
                return $globalIndexer->getIndex();
            }, $this->globalIndexers));
        }

        return $this->globalIndex;
    }

    public function onProjectOpen(Project $project): \Generator
    {
        if ($project->get('index.open_files') !== null) {
            return;
        }

        $openFilesIndex = $this->indexStorageFactory->createOpenedFilesIndex($project, $this->indexDataVersion);
        $projectFilesIndex = $this->indexStorageFactory->createProjectFilesIndex($project, $this->indexDataVersion);

        $index = new ChainedStorage(
            $openFilesIndex,
            new MergedStorage([
                $projectFilesIndex,
                yield $this->getGlobalIndex(),
            ])
        );

        $projectOnlyIndex = new ChainedStorage(
            $openFilesIndex,
            $projectFilesIndex
        );

        $project->set('index.open_files', $openFilesIndex);
        $project->set('index.project_files', $projectFilesIndex);
        $project->set('index.project_only', $projectOnlyIndex);
        $project->set('index', $index);

        return;
        yield;
    }

    public function onOpen(Document $document): \Generator
    {
        yield $this->onChange($document);
    }

    public function onChange(Document $document): \Generator
    {
        yield Priority::interactive(10);
        /** @var Project $project */
        $project = yield $this->documentStore->getProjectForDocument($document);

        /** @var WritableIndexStorage $openFilesIndex */
        $openFilesIndex = $project->get('index.open_files');

        yield $this->indexDocument($document, $openFilesIndex, $document->getVersion(), null);
        yield $this->eventDispatcher->dispatch(OnIndexingFinished::class);
    }

    public function onClose(Document $document): \Generator
    {
        yield Priority::interactive(10);
        /** @var Project $project */
        $project = yield $this->documentStore->getProjectForDocument($document);

        /** @var WritableIndexStorage $openFilesIndex */
        $openFilesIndex = $project->get('index.open_files');

        yield $this->clearDocument($document->getUri(), $openFilesIndex);
        yield $this->eventDispatcher->dispatch(OnIndexingFinished::class);
    }

    /**
     * @param Uri[] $uris
     */
    public function onFileChange(array $uris): \Generator
    {
        foreach ($uris as $uri) {
            // $this->logger->debug("File changed: $uri");
            /** @var Project[] $projects */
            $projects = yield $this->documentStore->getProjectsForUri($uri);

            foreach ($projects as $project) {
                yield $this->onProjectOpen($project);
                $indexStorage = $project->get('index.project_files');
                yield Recoil::execute(function () use ($project, $indexStorage, $uri) {
                    yield Priority::background();
                    yield $this->indexProject($project, $indexStorage, $uri, null);
                });
            }
        }
    }
}
