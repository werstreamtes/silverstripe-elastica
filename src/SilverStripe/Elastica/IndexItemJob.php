<?php

namespace SilverStripe\Elastica;

use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;

if (class_exists('AbstractQueuedJob')) {

    class IndexItemJob extends AbstractQueuedJob
    {

        private $itemType;
        private $itemID;
        private $stage;

        public function __construct($itemToIndex = null, $stage = null)
        {
            parent::__construct();

            $this->currentStep = -1;
            if ($itemToIndex) {
                $this->itemType = $itemToIndex->class;
                $this->itemID = $itemToIndex->ID;
                $this->stage = $stage;
                $this->totalSteps = 1;
            }

        }

        public function getJobType(): string
        {
            return QueuedJob::IMMEDIATE;
        }

        protected function getItem(): ?DataObject
        {
            return DataObject::get_by_id($this->itemType, $this->itemID, false);
        }

        public function getTitle(): string
        {
            $mode = 'Indexing';
            $stage = $this->stage == 'Live' ? 'Live' : 'Stage';
            $item = $this->getItem();
            return sprintf(_t('Elastica.INDEX_ITEM_JOB', $mode . ' "%s" in stage ' . $stage), $item ? $item->Title : 'item #' . $this->itemID);
        }

        public function process()
        {
            $stage = is_null($this->stage) ? null : ($this->stage == 'Stage' ? 'Stage' : 'Live');

            $item = $this->getItem();
            if ($item) {
                $searchService = Injector::inst()->create('ElasticaService');
                $searchService->index($item, $stage);
            }

            $this->currentStep = 1;
            $this->isComplete = true;
        }
    }
}
