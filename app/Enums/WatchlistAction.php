<?php

namespace App\Enums;

enum WatchlistAction: string
{
    case Added = 'added';
    case Removed = 'removed';
    case Pinned = 'pinned';
    case Unpinned = 'unpinned';
}
