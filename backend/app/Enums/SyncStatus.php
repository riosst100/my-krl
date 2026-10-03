<?php

namespace App\Enums;

enum SyncStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Success = 'success';
    case Partial = 'partial';   // finished, but some stations failed
    case Failed = 'failed';

    public function isFinished(): bool
    {
        return ! in_array($this, [self::Queued, self::Running], true);
    }
}
