<?php

namespace XD\EventTickets\App\Model;

use BaconQrCode\Renderer\Image\ImagickImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use XD\EventTickets\App\Controller\Authenticator;
use XD\EventTickets\App\Fields\QRCodeField;
use LeKoala\CmsActions\CustomAction;
use LeKoala\CmsActions\SilverStripeIcons;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/**
 * Class Device
 *
 * @property string Title
 * @property string Note
 * @property string Token
 * @property string UniqueID
 * @property string Brand
 * @property string Model
 * @property string DeviceID
 * @property string BundleID
 * @property string LastLogin
 */
class Device extends DataObject
{
    private static $table_name = 'EventTickets_Device';
    
    private static $db = [
        'Note' => 'Text',
        'Token' => 'Text',
        'Brand' => 'Varchar',
        'Model' => 'Varchar',
        'UniqueID' => 'Varchar',
        'LastLogin' => 'DBDatetime'
    ];

    private static $has_one = [
        'Owner' => Member::class
    ];

    private static $summary_fields = [
        'Title' => 'Name',
        'UniqueID' => 'Device ID',
        'Created.Nice' => 'Connected on',
        'LastLogin.Nice' => 'Last use'
    ];

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        $fields->removeByName('Token');
        $fields->addFieldsToTab('Root.Main', [
            TextField::create('Title', 'Name'),
            TextareaField::create('Note', 'Note'),
            ReadonlyField::create('UniqueID', 'UniqueID'),
            TextField::create('Brand', 'Brand'),
            TextField::create('Model', 'Model')
        ]);

        // connect to action, invalidates the existing token
        if (!$this->Token && $loginQR = $this->getLoginQR()) {
            $qrField = QRCodeField::create('LoginQR', _t(__CLASS__ . '.LoginQR', 'Login QR-code'), $loginQR)
                ->setDescription(_t(__CLASS__ . '.LoginQRDescription', 'Deze QR code blijft maar tijdelijk zichtbaar'));
            $fields->add($qrField);
        }

        $this->extend('updateCMSFields', $fields);
        return $fields;
    }

    public function getCMSActions()
    {
        $actions = parent::getCMSActions();

        $action = new CustomAction("createLoginQR", _t(__CLASS__ . '.CreateNewLoginQR', 'Create Login QR'));
        $action->setButtonIcon(SilverStripeIcons::ICON_ATTENTION);
        $action->setConfirmation(_t(__CLASS__ . '.ConfirmNewQR', 'Wanneer je een nieuwe QR-code genereert wordt een ingelogd appaat uitgelogd.'));
        $actions->push($action);

        return $actions;
    }


    public function createLoginQR() {
        $this->Token = null;
        $this->write();
        return _t(__CLASS__ . '.CreatedNewQR', 'Er is een nieuwe QR-code aangemaakt');
    }

    public function getLoginQR()
    {
        if (!$this->exists()) {
            return null;
        }

        if (!($member = $this->Owner()) || !$member->exists()) {
            return null;
        }

        // if no token exists create and store
        if (!$token = $this->Token) {
            $token = Authenticator::createTokenFor($member, $this);
            $this->Token = $member->encryptWithUserSettings($token);
            $this->write();
        }
        
        $data = Authenticator::createResponseData($member, $this, $token);
        $renderer = new ImageRenderer(
            new RendererStyle(400),
            new ImagickImageBackEnd()
        );

        $writer = new Writer($renderer);
        return base64_encode($writer->writeString(json_encode($data)));
    }
    
    /**
     * Get the title
     *
     * @return string
     */
    public function getTitle()
    {
        if (($brand = $this->Brand) && $model = $this->Model) {
            return "{$brand}, {$model}";
        } else {
            return parent::getTitle();
        }
    }

    /**
     * Find or make a new device
     *
     * @param $uniqueID
     * @param null $brand
     * @param null $model
     * @return Device|DataObject|null
     * @throws \ValidationException
     */
    public static function findOrMake($uniqueID, $brand = null, $model = null)
    {
        if (!$device = self::get()->find('UniqueID', $uniqueID)) {
            $device = self::create();
            $device->UniqueID = $uniqueID;
            $device->Brand = $brand;
            $device->Model = $model;
        }

        $device->LastLogin = time();
        $device->write();
        return $device;
    }
}
