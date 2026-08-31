<?php

namespace App\Modules\Platform\Services;

final readonly class WorkflowRuntimeSnapshot
{
    public function __construct(
        public string $module,
        /** @var array<int, array<string, mixed>> */
        public array $readiness = [],
        /** @var array<int, array<string, mixed>> */
        public array $pending = [],
    ) {}

    public function toArray(): array
    {
        return [
            'module' => $this->module,
            'readiness' => $this->readiness,
            'pending' => $this->pending,
        ];
    }
}
