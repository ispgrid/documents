<?php

namespace Ispgrid\Documents;

use App\Support\Permissions;

/**
 * The add-on's permission boxes. None is given to a role on install: Admin has every box, and operators tick the
 * rest on Roles. Each box ticks the one it needs to be useful (erase, then restore, then view, then the customers).
 */
final class Abilities
{
    public const VIEW = 'view-customer-documents';

    public const UPLOAD = 'upload-documents';

    public const EDIT = 'edit-documents';

    public const RESTORE = 'restore-documents';

    public const ERASE = 'erase-documents';

    public const TYPES = 'manage-document-types';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::VIEW, self::UPLOAD, self::EDIT, self::RESTORE, self::ERASE, self::TYPES];
    }

    public static function register(Permissions $permissions): void
    {
        $area = fn (): string => __('Customers');

        $permissions->register(self::VIEW, fn (): string => __('View customer documents'), $area, 'view-customers');
        $permissions->register(self::UPLOAD, fn (): string => __('Upload documents'), $area, 'view-customers');
        $permissions->register(self::EDIT, fn (): string => __('Edit and delete documents'), $area, self::VIEW);
        $permissions->register(self::RESTORE, fn (): string => __('Restore deleted documents'), $area, self::VIEW);
        $permissions->register(self::ERASE, fn (): string => __('Erase deleted documents'), $area, self::RESTORE);
        $permissions->register(self::TYPES, fn (): string => __('Document types'), $area);
    }
}
