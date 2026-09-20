<?php

namespace App\Services\Shopify;

use RuntimeException;

/**
 * Shopify gates theme-file writes (themeFilesUpsert) behind the write_themes
 * scope PLUS a manually-approved "protected scope exemption" - having the
 * scope alone still returns ACCESS_DENIED. Every theme-writing feature in
 * this app (safe-tier auto-fix, App & Script Impact actions, medium-risk
 * fixes) needs to tell this apart from a real bug: the fix logic worked
 * correctly right up until Shopify's authorization layer refused the write,
 * and no retry or code change on our side will fix that - only Shopify
 * approving the exemption request will.
 */
class ThemeWriteAccessDeniedException extends RuntimeException
{
    public const EXEMPTION_FORM_URL = 'https://docs.google.com/forms/d/e/1FAIpQLSfZTB1vxFC5d1-GPdqYunWRGUoDcOheHQzfK2RoEFEHrknt5g/viewform';
}
