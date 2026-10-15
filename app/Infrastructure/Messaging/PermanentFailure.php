<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use RuntimeException;

/** A failure retrying cannot fix (an invalid recipient, say): the message goes straight to the dead-letter queue. */
final class PermanentFailure extends RuntimeException {}
