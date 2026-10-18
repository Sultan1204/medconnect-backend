<?php

namespace App\Modules\Notifications\Exceptions;

use RuntimeException;

/** The push provider reported the device token as unregistered or expired. */
class InvalidPushToken extends RuntimeException {}
