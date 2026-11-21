<?php

declare(strict_types=1);

namespace App\Security\Social;

/**
 * The provider answered with an error or something unusable. The message is for the log and never contains tokens,
 * codes or secrets.
 */
final class SocialLoginException extends \RuntimeException {}
