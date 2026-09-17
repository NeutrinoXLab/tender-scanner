<?php

namespace App\Services;

use RuntimeException;

class SeapRequestPending extends RuntimeException
{
    public function __construct(public bool $listing)
    {
        parent::__construct('Așteaptă răspunsul workerului autorizat.');
    }
}
