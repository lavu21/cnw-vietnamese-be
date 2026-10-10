<?php

namespace App\Exceptions;

use RuntimeException;

/** The analyzer could not produce a result (upstream failure). */
class AnalysisFailedException extends RuntimeException
{
    public function isTimeout(): bool
    {
        return false;
    }
}
