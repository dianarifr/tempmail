<?php

declare(strict_types=1);

namespace TempMail\Inbox;

use RuntimeException;

/**
 * Dilempar saat rate limit terlampaui. Ditangkap di endpoint & dipetakan ke HTTP 429.
 */
final class LimitReached extends RuntimeException
{
}
