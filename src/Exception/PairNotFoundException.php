<?php

namespace App\Exception;

class PairNotFoundException extends \InvalidArgumentException
{
    public function __construct(string $pair)
    {
        parent::__construct(sprintf('Unknown trading pair "%s".', $pair));
    }
}
