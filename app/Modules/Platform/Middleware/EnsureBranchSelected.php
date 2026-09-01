<?php

namespace App\Modules\Platform\Middleware;

use App\Modules\Platform\Services\BranchContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureBranchSelected
{
    public function __construct(private readonly BranchContext $branchContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $branch = $request->attributes->get('selectedBranch') ?? $this->branchContext->resolve($request);

        if ($branch === null) {
            return redirect()->route('branches.index')->with('error', 'กรุณาเลือกสาขาที่มีสิทธิ์ใช้งาน');
        }

        $request->attributes->set('selectedBranch', $branch);

        return $next($request);
    }
}
