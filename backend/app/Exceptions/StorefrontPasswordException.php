<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the scanner reports a page redirected to Shopify's storefront
 * password gate - distinguished from a generic scan failure so RunAuditJob
 * can re-flag ShopInstallation.storefront_locked_at even when a password is
 * already saved (it was just wrong), instead of leaving the Dashboard/
 * Settings banner silently out of sync with what's actually blocking scans.
 */
class StorefrontPasswordException extends RuntimeException
{
}
