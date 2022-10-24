<?php

namespace SilverStripe\Elastica;

use Elastica\Document;
use Elastica\Type\Mapping;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * Adds elastic search integration to a data object.
 */
class Searchable extends DataExtension
{

    public static $mappings = [
        'Boolean' => 'integer',
        'Decimal' => 'double',
        'Double' => 'double',
        'Enum' => 'string',
        'Float' => 'float',
        'HTMLText' => 'string',
        'HTMLVarchar' => 'string',
        'Int' => 'integer',
        'SS_Datetime' => 'date',
        'Text' => 'string',
        'Varchar' => 'string',
        'Year' => 'integer',
        'MultiValueField' => 'string'
    ];

    private $service;

    /**
     * @param ElasticaService $service
     */
    public function __construct(ElasticaService $service)
    {
        $this->service = $service;
        parent::__construct();
    }

    /**
     * @return string
     */
    public function getElasticaType(): string
    {
        return $this->ownerBaseClass;
    }

    /**
     * Gets an array of elastic field definitions.
     *
     * @return array
     */
    public function getElasticaFields(): array
    {

        $db = DataObject::getSchema()->databaseFields(get_class($this->owner));
        $fields = $this->owner->searchableFields();
        $result = array();

        foreach ($fields as $name => $params) {
            $type = null;
            $spec = array();

            if (array_key_exists($name, $db)) {
                $class = $db[$name];

                if (($pos = strpos($class, '('))) {
                    $class = substr($class, 0, $pos);
                }

                if (array_key_exists($class, self::$mappings)) {
                    $spec['type'] = self::$mappings[$class];
                }
            }

            $result[$name] = $spec;
        }

        $result['LastEdited'] = ['type' => 'date'];
        $result['Created'] = ['type' => 'date'];
        $result['ID'] = ['type' => 'integer'];
        $result['ParentID'] = ['type' => 'integer'];
        $result['Sort'] = ['type' => 'integer'];
        $result['Name'] = ['type' => 'string'];
        $result['MenuTitle'] = ['type' => 'string'];
        $result['ShowInSearch'] = ['type' => 'integer'];
        $result['ClassName'] = ['type' => 'string'];
        $result['ClassNameHierarchy'] = ['type' => 'string'];
        $result['LastIndexed'] = ['type' => 'date'];

        // fix up dates
        foreach ($result as $field => $spec) {
            if (isset($spec['type']) && ($spec['type'] == 'date')) {
                $spec['format'] = 'yyyy-MM-dd HH:mm:ss';
                $result[$field] = $spec;
            }
        }

        if (isset($result['Content']) && count($result['Content'])) {
            $spec = $result['Content'];
            $spec['store'] = false;
            $result['Content'] = $spec;
        }
        if (method_exists($this->owner, 'updateElasticMappings')) {
            $this->owner->updateElasticMappings($result);
        }
        $this->owner->extend('updateElasticMappings', $result);

        return $result;
    }

    /**
     * @return Mapping
     */
    public function getElasticaMapping(): Mapping
    {
        $mapping = new Mapping();
        $mapping->setProperties($this->getElasticaFields());
        $mapping->setParam('date_detection', false);
        return $mapping;
    }

    /**
     * @param $stage
     * @return Document
     */
    public function getElasticaDocument($stage = 'Stage'): Document
    {
        $fields = [];

        foreach ($this->owner->getElasticaFields() as $field => $config) {
            if ($this->owner->hasField($field)) {
                $fields[$field] = $this->owner->$field;
            }
        }

        if ($this->owner->hasExtension('Versioned')) {
            // add in the specific stage(s)
            $fields['SS_Stage'] = [$stage];
            $id = get_class($this->owner) . '_' . $this->owner->ID . '_' . $stage;
        } else {
            $fields['SS_Stage'] = array('Live', 'Stage');
            $id = get_class($this->owner) . '_' . $this->owner->ID;
        }

        if ($this->owner->hasExtension('Hierarchy') || $this->owner->hasField('ParentID')) {
            $fields['ParentsHierarchy'] = $this->getParentsHierarchyField();
        }

        if (!isset($fields['ClassNameHierarchy'])) {
            $classes = array_values(ClassInfo::ancestry($this->owner->class));
            if (!$classes) {
                $classes = [$this->owner->class];
            }
            $fields['ClassNameHierarchy'] = $classes;
        }

        if (!isset($fields['ClassName'])) {
            $fields['ClassName'] = $this->owner->class;
        }

        if (!isset($fields['LastIndexed'])) {
            $fields['LastIndexed'] = date('Y-m-d H:i:s');
        }

        $this->owner->extend('updateSearchableData', $fields);

        return new Document($id, $fields);
    }

    /**
     * Get a solr field representing the parents hierarchy (if applicable)
     */
    protected function getParentsHierarchyField(): array
    {
        // see if we've got Parent values
        $parents = [];

        $parent = $this->owner;
        while ($parent && $parent->ParentID) {
            $parents[] = $parent->ParentID;
            $parent = $parent->Parent();
            // fix for odd behaviour - in some instance a node is being assigned as its own parent.
            if ($parent->ParentID == $parent->ID) {
                $parent = null;
            }
        }
        return $parents;
    }

    /**
     * Can this item be shown in a search result?
     *
     * Allows a base type to override impl
     *
     * @return boolean
     */
    public function canShowInSearch(): bool
    {
        if ($this->owner->hasField('ShowInSearch')) {
            return $this->owner->ShowInSearch;
        }

        return true;
    }

    /**
     * Updates the record in the search index.
     */
    public function onAfterWrite()
    {
        if (Config::inst()->get('ElasticSearch', 'disabled')) {
            return;
        }
        $stage = Versioned::get_stage();

        if (ClassInfo::exists('QueuedJob')) {
            $indexing = new IndexItemJob($this->owner, $stage);
            singleton('QueuedJobService')->queueJob($indexing);
        } else {
            $this->service->index($this->owner, $stage);
        }
    }

    /**
     * Removes the record from the search index.
     */
    public function onAfterDelete()
    {
        if (Config::inst()->get('ElasticSearch', 'disabled')) {
            return;
        }

        $stage = Versioned::get_stage();
        $this->service->remove($this->owner, $stage);
    }

    /**
     * @return void
     */
    public function onAfterPublish()
    {
        if (Config::inst()->get('ElasticSearch', 'disabled')) {
            return;
        }

        if (ClassInfo::exists('QueuedJob')) {
            $indexing = new IndexItemJob($this->owner, "Live");
            singleton('QueuedJobService')->queueJob($indexing);
        } else {
            $this->service->index($this->owner, "Live");
        }
    }

    /**
     * If unpublished, we delete from the index then reindex the 'stage' version of the
     * content
     *
     * @return
     */
    function onAfterUnpublish()
    {
        if (Config::inst()->get('ElasticSearch', 'disabled')) {
            return;
        }

        $this->service->remove($this->owner, 'Live');
        $this->service->index($this->owner, 'Stage');
    }
}
