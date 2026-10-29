<?php

namespace App\Enums;

enum CandidateStatus: string
{
    /** Passed the filters; waiting for (or after) a deep check. */
    case Candidate = 'candidate';
    /** Promoted to the tracked token list. */
    case Tracked = 'tracked';
    /** Failed liquidity, age or safety checks. */
    case Rejected = 'rejected';
}
