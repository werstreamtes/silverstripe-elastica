<?php

namespace SilverStripe\Elastica;

use Elastica\Document;
use Elastica\Exception\BulkException;
use Elastica\Exception\Connection\HttpException;
use Elastica\Client;
use Elastica\Index;
use Elastica\Query;
use Elastica\Type\Mapping;
use ReflectionException;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Extensible;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * A service used to interact with elastic search.
 */
class ElasticaService
{

    /**
     * Custom mapping definitions
     *
     * Format of array(
     *   'type' => array(
     *      'properties' => array(
     *         'FieldA' => array('type' => elastictype, 'etc' => other),
     *         'FieldB' => array('type' => elastictype, 'etc' => other),
     *      ),
     *      'params' => array(
     *         'ParamA' => array(...)
     *   )
     * )
     *
     * @var array
     */
    public $mappings = [];

    /**
     * Settings for Index creation
     *
     * @var array
     */
    public $indexSettings = [];

    /**
     * @var Document[]
     */
    protected $buffer = [];

    /**
     * @var bool controls whether indexing operations are buffered or not
     */
    protected $buffered = false;

    /**
     * @var Client
     */
    private $client;

    /**
     * @var string
     */
    private $index;

    public $enabled = true;

    protected $connected = true;

    public $searchableExtension = 'SilverStripe\\Elastica\\Searchable';

    /**
     * @param Client $client
     * @param string $index
     */
    public function __construct(Client $client, $index)
    {
        $this->client = $client;
        $this->index = $index;
    }

    /**
     * @return Client
     */
    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * @return Index
     */
    public function getIndex(): Index
    {
        return $this->getClient()->getIndex($this->index);
    }

    /**
     * Performs a search query and returns a result list.
     *
     * @param Query|string|array $query
     * @return ResultList
     */
    public function search($query): ResultList
    {
        return new ResultList($this->getIndex(), Query::create($query));
    }

    /**
     * Either creates or updates a record in the index.
     *
     * @param Searchable $record
     * @param string $stage
     */
    public function index($record, $stage = 'Stage')
    {
        if (!$this->enabled) {
            return;
        }
        if ($record->canShowInSearch()) {
            $document = $record->getElasticaDocument($stage);
            $type = $record->getElasticaType();

            $this->indexDocument($document, $type);
        } else {
            $this->remove($record, $stage = 'Stage');
        }
    }

    /**
     * @param $document
     * @param $type
     * @return void
     */
    public function indexDocument($document, $type)
    {
        if (!$this->enabled) {
            return;
        }
        if (!$this->connected) {
            return;
        }
        if ($this->buffered) {
            if (array_key_exists($type, $this->buffer)) {
                $this->buffer[$type][] = $document;
            } else {
                $this->buffer[$type] = array($document);
            }
        } else {
            $index = $this->getIndex();
            try {
                $index->getType($type)->addDocument($document);
                $index->refresh();
            } catch (HttpException $ex) {
                $this->connected = false;

                // TODO LOG THIS ERROR
                user_error($ex);
            }
        }
    }

    /**
     * Begins a bulk indexing operation where documents are buffered rather than
     * indexed immediately.
     */
    public function startBulkIndex()
    {
        $this->buffered = true;
    }

    /**
     * Ends the current bulk index operation and indexes the buffered documents.
     */
    public function endBulkIndex()
    {
        if (!$this->connected) {
            return;
        }

        $index = $this->getIndex();

        try {
            foreach ($this->buffer as $type => $documents) {
                $index->getType($type)->addDocuments($documents);
                $index->refresh();
            }
        } catch (HttpException $ex) {
            $this->connected = false;
            user_error($ex);
        } catch (BulkException $be) {
            user_error($be);
            throw $be;
        }

        $this->buffered = false;
        $this->buffer = [];
    }

    /**
     * Deletes a record from the index.
     *
     * @param Searchable $record
     * @param string $stage
     * @return bool
     */
    public function remove($record, $stage = 'Stage'): bool
    {
        $index = $this->getIndex();
        $type = $index->getType($record->getElasticaType());

        try {
            $type->deleteDocument($record->getElasticaDocument($stage));
        } catch (\Exception $ex) {
            user_error($ex);
            return false;
        }

        return true;
    }

    /**
     * Creates the index and the type mappings.
     */
    public function define()
    {
        $index = $this->getIndex();

        if (!$index->exists()) {
            $index->create($this->indexSettings);
        }

        $this->createMappings($index);
    }

    /**
     * Define all known mappings
     * @param Index $index
     * @throws ReflectionException
     */
    protected function createMappings(Index $index)
    {
        foreach ($this->getIndexedClasses() as $class) {
            /** @var $sng Searchable */
            $sng = singleton($class);

            $type = $sng->getElasticaType();
            if (isset($this->mappings[$type])) {
                // captured later
                continue;
            }

            $mapping = $sng->getElasticaMapping();
            $mapping->setType($index->getType($type));
            $mapping->send();
        }

        if ($this->mappings) {
            foreach ($this->mappings as $type => $definition) {
                $mapping = new Mapping();
                $mapping->setProperties($definition['properties']);
                $mapping->setParam('date_detection', false);
                if (isset($definition['params'])) {
                    foreach ($definition['params'] as $paramName => $paramConfig) {
                        $mapping->setParam($paramName, $paramConfig);
                    }
                }
                $mapping->setType($index->getType($type));

                $mapping->send();
            }
        }
    }

    /**
     * Re-indexes each record in the index.
     * @param null $logFunc
     * @throws ReflectionException
     */
    public function refresh($logFunc = null)
    {
        $index = $this->getIndex();
        if (!$logFunc) {
            $logFunc = function ($msg) {

            };
        }

        foreach ($this->getIndexedClasses() as $class) {
            $logFunc("Indexing items of type $class");
            $start = date('Y-m-d H:i:s');
            $limit = 1000;
            $total = $class::get()->count();

            for ($offset = 0; $offset < $total; $offset += $limit) {
                $this->startBulkIndex();
                foreach ($class::get()->limit($limit, $offset) as $record) {
                    $logFunc("Indexing " . $record->Title);
                    $this->index($record);
                }

                if (Extensible::has_extension($class, 'Versioned')) {
                    $live = Versioned::get_by_stage($class, 'Live');
                    foreach ($live->limit($limit, $offset) as $liveRecord) {
                        $logFunc("Indexing Live record " . $liveRecord->Title);
                        $this->index($liveRecord, 'Live');
                    }
                }
                $this->endBulkIndex();
            }

            // Remove any document of this type, that has not been updated during the refresh:
            $logFunc("Removing obsolete Documents of type $class");
            /** @var $sng Searchable */
            $sng = singleton($class);
            $type = $index->getType($sng->getElasticaType());

            $boolQuery = new Query\BoolQuery();
            $boolQuery->addMust(new Query\Range("LastIndexed", ["lt" => $start, "format" => 'yyyy-MM-dd HH:mm:ss']));
            $boolQuery->addMust(new Query\MatchQuery("ClassName", $class));

            $results = $type->search($boolQuery, ["limit" => $limit]);
            while ($results->count() > 0) {
                $obsoleteIds = array_map(function ($result) {
                    return $result->getId();
                }, $results->getResults());
                $type->deleteIds($obsoleteIds);
                $results = $type->search($boolQuery, ["limit" => $limit]);
            }
        }

    }

    /**
     * Gets the classes which are indexed (i.e. have the extension applied).
     *
     * @return array
     * @throws ReflectionException
     */
    public function getIndexedClasses(): array
    {
        $classes = [];

        foreach (ClassInfo::subclassesFor(DataObject::class) as $candidate) {
            if (singleton($candidate)->hasExtension($this->searchableExtension)) {
                $classes[] = $candidate;
            }
        }

        return $classes;
    }

}
