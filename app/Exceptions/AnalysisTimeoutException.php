<?php

namespace App\Exceptions;

final class AnalysisTimeoutException extends AnalysisFailedException
{
    public function isTimeout(): bool
    {
        return true;
    }
}
