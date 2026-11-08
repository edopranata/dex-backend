<?php

namespace App\Services\MarketData;

use RuntimeException;

/**
 * GoPlus answered with an error code instead of data, such as its request limit. The answer looks like a
 * normal empty result, so without this the check would be skipped without a trace.
 */
class GoPlusRefusedException extends RuntimeException {}
