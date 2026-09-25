<?php

namespace App\Exception;

class AlertCapacityReachedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Alert capacity reached. Try again later.');
    }
}
