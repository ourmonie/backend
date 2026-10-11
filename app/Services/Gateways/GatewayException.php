<?php

namespace App\Services\Gateways;

use RuntimeException;

/**
 * Raised when a gateway is asked to do something its configuration does not
 * allow - no keys saved, wrong environment, provider rejected the call.
 *
 * Controllers translate this into a 422 the dashboard can render; webhooks
 * translate it into a non-200 so the provider retries.
 */
class GatewayException extends RuntimeException
{
}
