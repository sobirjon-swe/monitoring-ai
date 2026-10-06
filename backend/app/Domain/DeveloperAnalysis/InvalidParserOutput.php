<?php

namespace App\Domain\DeveloperAnalysis;

use RuntimeException;

final class InvalidParserOutput extends RuntimeException
{
    public function __construct()
    {
        // Never retain raw child output, stderr, or a chained exception.
        parent::__construct('Invalid parser output.');
    }
}
