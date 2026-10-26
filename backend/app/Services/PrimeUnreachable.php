<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Thrown instead of contacting PRIME while it is known to be down (see
 * ExternalUserAuthService::guard()). Counts as "PRIME unreachable".
 */
final class PrimeUnreachable extends RuntimeException {}
