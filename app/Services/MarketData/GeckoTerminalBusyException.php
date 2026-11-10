<?php

namespace App\Services\MarketData;

use RuntimeException;

/**
 * No GeckoTerminal request slot came up within the time the caller could wait, or GeckoTerminal asked
 * everyone to pause. Nothing was sent, so the caller can simply try again on its next run.
 */
class GeckoTerminalBusyException extends RuntimeException {}
