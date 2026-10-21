<?php

namespace App\Services\Sefaz;

enum SyncOutcome: string
{
    case NotDue = 'not_due';
    case Locked = 'locked';
    case UpToDate = 'up_to_date';
    case MorePending = 'more_pending';
    case Blocked = 'blocked';
    case Failed = 'failed';
}
