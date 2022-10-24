<?php

namespace SilverStripe\Elastica;

use SilverStripe\Control\Director;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Security\Permission;
use SilverStripe\Versioned\Versioned;

/**
 * @author marcus
 */
class ReindexItemsTask extends BuildTask
{
    protected $title = 'Reindex specific items in Elastic';
    protected $description = 'Reindex specific items in Elastic';

    /**
     * @param $request
     * @return void
     */
    public function run($request)
    {
        if (!(Permission::check('ADMIN') || Director::is_cli())) {
            exit("Invalid");
        }

        $service = singleton('ElasticaService');

        $items = explode(',', $request->getVar('ids'));
        if (!count($items)) {
            return;
        }

        $baseType = $request->getVar('base') ? $request->getVar('base') : 'SiteTree';
        $recurse = (bool)$request->getVar('recurse');

        foreach ($items as $id) {
            $id = (int)$id;
            if (!$id) {
                continue;
            }
            Versioned::set_stage('Stage');
            $item = $baseType::get()->byID($id);
            if ($item) {
                $this->reindex($item, $recurse, $baseType, $service, 'Stage');
            }
            Versioned::set_stage('Live');
            $item = $baseType::get()->byID($id);
            if ($item) {
                $this->reindex($item, $recurse, $baseType, $service, 'Live');
            }
        }

    }

    /**
     * @param $item
     * @param $recurse
     * @param $baseType
     * @param $service
     * @param $stage
     * @return void
     */
    protected function reindex($item, $recurse, $baseType, $service, $stage)
    {
        echo "Reindex $item->Title \n<br/>";
        $service->index($item, $stage);
        if ($recurse) {
            $children = $baseType::get()->filter('ParentID', $item->ID);
            if ($children) {
                foreach ($children as $child) {
                    $this->reindex($child, $recurse, $baseType, $service, $stage);
                }
            }
        }
    }

}
