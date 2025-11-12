<?php

namespace XD\EventTickets\App\Fields;

use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\TextField;

class QRCodeField extends TextField
{
    public function __construct($name, $title = null, $value = null)
    {
        parent::__construct($name, $title, $value);
    }
}