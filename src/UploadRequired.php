<?php

namespace Ispgrid\Documents;

use App\Models\AppSetting;

/**
 * Whether a customer needs at least one document before the customer form saves: the app setting
 * documents.upload_required (System, then Add-ons). Off until the ISP switches it on.
 */
final class UploadRequired
{
    public const KEY = 'documents.upload_required';

    public static function isOn(): bool
    {
        return AppSetting::valueOf(self::KEY) === '1';
    }

    public static function put(bool $on): void
    {
        AppSetting::put(self::KEY, $on ? '1' : '0');
    }
}
