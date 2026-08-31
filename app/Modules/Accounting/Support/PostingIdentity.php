<?php

namespace App\Modules\Accounting\Support;

final class PostingIdentity
{
    public static function key(string $sourceType, string $eventCode, string $sourceId): string
    {
        return self::hash(['v1', $sourceType, $eventCode, $sourceId]);
    }

    public static function fingerprint(array $normalizedPayload): string
    {
        return self::hash($normalizedPayload);
    }

    private static function hash(array $value): string
    {
        return hash('sha256', json_encode(self::canonicalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function canonicalize(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => is_array($item) ? self::canonicalize($item) : $item, $value);
    }
}
