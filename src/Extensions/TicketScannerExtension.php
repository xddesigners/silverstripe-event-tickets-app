<?php

namespace XD\EventTickets\App\Extensions;

use XD\EventTickets\App\Model\Device;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\ORM\DataExtension;
use SilverStripe\Security\Member;

/**
 * Class SiteConfigExtension
 *
 * @property TicketScannerExtension|Member $owner
 * @property string TicketScannerAppToken
 * @method HasManyList ScanDevices()
 */
class TicketScannerExtension extends DataExtension
{
    private static $has_many = array(
        'ScanDevices' => Device::class
    );

    public function updateCMSFields(FieldList $fields)
    {
        if ($this->owner->exists()) {
            $config = GridFieldConfig_RecordEditor::create();
            $fields->addFieldToTab(
                'Root.ScanDevices',
                GridField::create('ScanDevices', 'Scan devices', $this->owner->ScanDevices(), $config)
            );
        }

        return $fields;
    }
}
