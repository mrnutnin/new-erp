<?php

namespace App\Modules\Platform\Support;

final class ContextSelection
{
    public function nextRoute(?int $programId, bool $requiresBranch, bool $requiresWarehouse, ?int $branchId, string $entryRoute): string
    {
        if ($programId === null) {
            return 'programs.index';
        }

        if (($requiresBranch || $requiresWarehouse) && $branchId === null) {
            return 'branches.index';
        }

        return $entryRoute;
    }
}
