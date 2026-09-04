<?php

namespace Tests\Unit;

use App\Models\User;
use App\Modules\Platform\Middleware\EnsureProgramSelected;
use App\Modules\Platform\Services\BranchContext;
use App\Modules\Platform\Services\ModuleCapability;
use App\Modules\Platform\Services\WarehouseContext;
use App\Modules\Settings\Services\GlobalSettings;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class RouteScopedProgramAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('program_user');
        Schema::dropIfExists('programs');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });
        Schema::create('programs', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->boolean('is_enabled')->default(true);
            $table->softDeletes();
        });
        Schema::create('program_user', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('program_id');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('program_user');
        Schema::dropIfExists('programs');
        Schema::dropIfExists('users');
        Mockery::close();
        parent::tearDown();
    }

    public function test_required_program_is_resolved_from_route_not_session(): void
    {
        $user = User::query()->create();
        $accountingId = $this->insertProgram('accounting');
        $assetId = $this->insertProgram('asset');
        $user->programs()->attach([$accountingId, $assetId]);

        $request = Request::create('/accounting', 'GET');
        $request->setUserResolver(fn (): User => $user);
        $request->setLaravelSession(app('session')->driver());
        $request->session()->put('selected_program_id', $assetId);

        $capability = new ModuleCapability(Mockery::mock(GlobalSettings::class));
        $middleware = new EnsureProgramSelected(new BranchContext, new WarehouseContext, $capability);

        $response = $middleware->handle($request, fn (Request $request): Response => response('ok'), 'accounting');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('accounting', $request->attributes->get('selectedProgram')->code);
    }

    public function test_missing_required_program_is_rejected_even_when_session_points_elsewhere(): void
    {
        $user = User::query()->create();
        $assetId = $this->insertProgram('asset');
        $user->programs()->attach($assetId);

        $request = Request::create('/accounting', 'GET');
        $request->setUserResolver(fn (): User => $user);
        $request->setLaravelSession(app('session')->driver());
        $request->session()->put('selected_program_id', $assetId);

        $middleware = new EnsureProgramSelected(
            new BranchContext,
            new WarehouseContext,
            new ModuleCapability(Mockery::mock(GlobalSettings::class)),
        );

        $response = $middleware->handle($request, fn (): Response => response('ok'), 'accounting');

        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('/select-program', $response->headers->get('Location'));
    }

    private function insertProgram(string $code): int
    {
        return (int) \DB::table('programs')->insertGetId(['code' => $code, 'is_enabled' => true]);
    }
}
