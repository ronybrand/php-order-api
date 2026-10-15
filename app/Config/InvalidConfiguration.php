<?php

declare(strict_types=1);

namespace App\Config;

use RuntimeException;

/** An unusable or unsafe configuration: the process refuses to start instead of running quietly insecure. */
final class InvalidConfiguration extends RuntimeException {}
