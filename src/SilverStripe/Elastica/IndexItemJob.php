<?php

namespace SilverStripe\Elastica;

if (class_exists('AbstractQueuedJob')) {
    class IndexItemJob extends \AbstractQueuedJob
    {
        public function __construct($itemToIndex = null, $stage = null) {
            $this->currentStep = -1;
            if ($itemToIndex) {
                $this->itemType = $itemToIndex->class;
                $this->itemID = $itemToIndex->ID;

                $this->stage = $stage;

                $this->totalSteps = 1;
            }
        }

        public function getJobType() {
            return \QueuedJob::IMMEDIATE;
        }

        protected function getItem() {
            if (\ClassInfo::exists('Subsite')) {
                \Subsite::disable_subsite_filter(true);
            }
            $item = \DataObject::get_by_id($this->itemType, $this->itemID);
            if (\ClassInfo::exists('Subsite')) {
                \Subsite::disable_subsite_filter(false);
            }
            return $item;
        }

        public function getTitle() {
            $mode = 'Indexing';
            $stage = $this->stage == 'Live' ? 'Live' : 'Stage';
            $item = $this->getItem();
            return sprintf(_t('Elastica.INDEX_ITEM_JOB', $mode . ' "%s" in stage '.$stage), $item ? $item->Title : 'item #'.$this->itemID);
        }

        public function process() {
            $stage = is_null($this->stage) ? null : ($this->stage == 'Stage' ? 'Stage' : 'Live');

            $item = $this->getItem();
            if ($item) {
                $searchService = \Injector::inst()->create('ElasticaService');
                $searchService->index($item, $stage);
            }

            $this->currentStep = 1;
            $this->isComplete = true;
        }
    }
}