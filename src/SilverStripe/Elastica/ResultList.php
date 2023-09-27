<?php

namespace SilverStripe\Elastica;

use ArrayIterator;
use Elastica\Index;
use Elastica\Query;
use Elastica\Result;
use Elastica\ResultSet;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\Limitable;
use SilverStripe\ORM\Map;
use SilverStripe\ORM\PaginatedList;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\ArrayData;
use SilverStripe\View\ViewableData;

/**
 * A list wrapper around the results from a query. Note that not all operations are implemented.
 */
class ResultList extends ViewableData implements Limitable
{

    private $index;
    private $query;

    protected $dataObjects;
    protected $results;
    protected $resultSet;

    /**
     * @param Index $index
     * @param Query $query
     */
    public function __construct(Index $index, Query $query)
    {
        parent::__construct();
        $this->index = $index;
        $this->query = $query;
    }

    /**
     * @return void
     */
    public function __clone()
    {
        $this->query = clone $this->query;
    }

    /**
     * @return Index
     */
    public function getIndex(): Index
    {
        return $this->index;
    }

    /**
     * @return Query
     */
    public function getQuery(): Query
    {
        return $this->query;
    }

    /**
     * @return ResultSet
     */
    public function getResultSet(): ResultSet
    {
        if (!$this->resultSet) {
            $this->resultSet = $this->index->search($this->query);
        }
        return $this->resultSet;
    }

    /**
     * @return ArrayIterator
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->toArray());
    }

    /**
     * @param int $limit
     * @param int $offset
     * @return ResultList
     */
    public function limit($limit, $offset = 0): ResultList
    {
        $list = clone $this;

        $list->getQuery()->setSize($limit);
        $list->getQuery()->setFrom($offset);

        return $list;
    }

    /**
     * @return int
     */
    public function getTotalResults(): int
    {
        return $this->getResultSet()->getTotalHits();
    }

    /**
     * @return int
     */
    public function getTimeTaken(): int
    {
        return $this->getResultSet()->getTotalTime();
    }

    /**
     * @return array
     */
    public function getAggregations(): array
    {
        return $this->getResultSet()->getAggregations();
    }

    /**
     * The paginated result set that is rendered onto the search page.
     *
     * @param int $limit
     * @param int $start
     * @return PaginatedList
     */
    public function getDataObjects($limit = 0, $start = 0): PaginatedList
    {

        return PaginatedList::create($this->toArrayList())
            ->setPageLength($limit)
            ->setPageStart($start)
            ->setTotalItems($this->getTotalResults())
            ->setLimitItems(false);
    }

    /**
     * Converts results of type {@link \Elastica\Result}
     * into their respective {@link DataObject} counterparts.
     *
     * @return array DataObject[]
     */
    public function toArray($evaluatePermissions = false): array
    {
        if ($this->dataObjects) {
            return $this->dataObjects;
        }

        $result = [];

        /** @var $found Result[] */
        $found = $this->getResultSet();
        $needed = [];
        $retrieved = [];

        foreach ($found->getResults() as $item) {
            $data = $item->getData();

            $type = isset($data['ClassName']) ? $data['ClassName'] : $item->getType();
            $bits = explode('_', $item->getId());
            $id = $item->getId();

            if (count($bits) == 3) {
                list($type, $id, $stage) = $bits;
            } else if (count($bits) == 2) {
                list($type, $id) = $bits;
                $stage = Versioned::get_stage();
            } else {
                $stage = Versioned::get_stage();
            }

            if (!$type || !$id) {
                error_log("Invalid elastic document ID {$item->getId()}");
                continue;
            }

            // a double sanity check for the stage here.
            if ($currentStage = Versioned::get_stage()) {
                if ($currentStage != $stage) {
                    continue;
                }
            }

            if (class_exists($type)) {
                $object = DataObject::get_by_id($type, $id);
            } else {
                $object = ArrayData::create($item->getSource());
            }


            if ($object) {
                // check that the user has permission
                if ($item->getScore()) {
                    $object->SearchScore = $item->getScore();
                }

                $canAdd = true;
                if ($evaluatePermissions) {
                    // check if we've got a way of evaluating perms
                    if ($object->hasMethod('canView')) {
                        $canAdd = $object->canView();
                    }
                }

                if (!$evaluatePermissions || $canAdd) {
                    if ($object->hasMethod('canShowInSearch')) {
                        if ($object->canShowInSearch()) {
                            $result[] = $object;
                        }
                    } else {
                        $result[] = $object;
                    }
                }
            } else {
                error_log("Object {$item->getId()} is no longer in the system");
            }
        }

        $this->dataObjects = $result;
        return $result;
    }

    /**
     * @return ArrayList
     */
    public function toArrayList(): ArrayList
    {
        return new ArrayList($this->toArray());
    }

    /**
     * @return array
     */
    public function toNestedArray(): array
    {
        $result = [];

        foreach ($this as $record) {
            $result[] = $record->toMap();
        }

        return $result;
    }

    public function first()
    {
        // TODO
    }

    public function last()
    {
        // TODO: Implement last() method.
    }

    /**
     * @param $key
     * @param $title
     * @return Map
     */
    public function map($key = 'ID', $title = 'Title'): Map
    {
        return $this->toArrayList()->map($key, $title);
    }

    /**
     * @param $col
     * @return array
     */
    public function column($col = 'ID'): array
    {
        if ($col == 'ID') {
            $ids = [];

            foreach ($this->getResultSet()->getResults() as $result) {
                $ids[] = $result->getId();
            }

            return $ids;
        } else {
            return $this->toArrayList()->column($col);
        }
    }

    /**
     * @param $callback
     * @return ArrayList
     */
    public function each($callback): ArrayList
    {
        return $this->toArrayList()->each($callback);
    }

    /**
     * @return int
     */
    public function count(): int
    {
        return count($this->toArray());
    }

    /**
     * @param mixed $offset
     * @throws \Exception
     * @ignore
     */
    public function offsetExists(mixed $offset): bool
    {
        throw new \Exception();
    }

    /**
     * @param mixed $offset
     * @throws \Exception
     * @ignore
     */
    public function offsetGet(mixed $offset): mixed
    {
        throw new \Exception();
    }

    /**
     * @param mixed $offset
     * @param mixed $value
     * @throws \Exception
     * @ignore
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \Exception();
    }

    /**
     * @param $offset
     * @throws \Exception
     * @ignore
     */
    public function offsetUnset(mixed $offset): void
    {
        throw new \Exception();
    }

    /**
     * @param $item
     * @throws \Exception
     * @ignore
     */
    public function add($item)
    {
        throw new \Exception();
    }

    /**
     * @param $item
     * @throws \Exception
     * @ignore
     */
    public function remove($item)
    {
        throw new \Exception();
    }

    /**
     * @param $key
     * @param $value
     * @throws \Exception
     * @ignore
     */
    public function find($key, $value)
    {
        throw new \Exception();
    }

}
