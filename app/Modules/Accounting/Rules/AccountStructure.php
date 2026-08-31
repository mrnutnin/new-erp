<?php

namespace App\Modules\Accounting\Rules;

final class AccountStructure
{
    public const MAX_LEVEL = 5;

    public static function parentIsValid(bool $isActive, bool $isPostable, int $parentTypeId, int $accountTypeId): bool
    {
        return $isActive && ! $isPostable && $parentTypeId === $accountTypeId;
    }

    public static function level(?int $parentLevel): int
    {
        return ($parentLevel ?? 0) + 1;
    }

    public static function levelIsValid(int $level): bool
    {
        return $level >= 1 && $level <= self::MAX_LEVEL;
    }

    public static function canBePostable(int $childCount): bool
    {
        return $childCount === 0;
    }

    public static function canDeactivate(int $activeChildCount): bool
    {
        return $activeChildCount === 0;
    }

    public static function canDelete(int $childCount): bool
    {
        return $childCount === 0;
    }

    public static function controlTypeIsValid(?string $controlType, bool $isPostable): bool
    {
        return $controlType === null || $isPostable;
    }
}
