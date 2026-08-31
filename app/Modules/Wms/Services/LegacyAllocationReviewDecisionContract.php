<?php

namespace App\Modules\Wms\Services;

use InvalidArgumentException;

/**
 * Read-only decision vocabulary for legacy reviews.
 *
 * This intentionally does not mutate an allocation. A future controlled
 * decision flow must provide evidence and approval before changing state.
 */
final class LegacyAllocationReviewDecisionContract
{
    public const REVIEW_REQUIRED = 'REVIEW_REQUIRED';

    public const ESCALATE = 'ESCALATE';

    public function normalize(string $decision): string
    {
        $decision = strtoupper(trim($decision));
        if (! in_array($decision, [self::REVIEW_REQUIRED, self::ESCALATE], true)) {
            throw new InvalidArgumentException('Legacy review อนุญาตเฉพาะ REVIEW_REQUIRED หรือ ESCALATE');
        }

        return $decision;
    }

    public function isMutationAllowed(): bool
    {
        return false;
    }
}
