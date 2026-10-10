<?php

namespace App\Services;

/**
 * A NIN check failed. The message is written for the applicant, not the log -
 * it is surfaced directly on the join form.
 */
class NinVerificationException extends \RuntimeException
{
}
