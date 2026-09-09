<?php

namespace App\Exception;

class AlertNotFoundException extends \RuntimeException
{
    public function __construct(string $id)
    {
        parent::__construct(sprintf('Alert "%s" was not found.', $id));
    }
}
