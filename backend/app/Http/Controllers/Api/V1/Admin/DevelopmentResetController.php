<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Development\DevelopmentResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DevelopmentResetController extends Controller
{
    private const ARM_MINUTES = 5;

    private const MODE_FULL = 'FULL';

    private const MODE_TEST = 'TEST_ONLY';

    public function status(Request $request, DevelopmentResetService $reset): JsonResponse
    {
        $user = $this->assertSuperAdmin($request);
        $allowed = $this->isAllowedEnvironment();

        $payload = [
            'allowed' => $allowed,
            'environment' => app()->environment(),
            'database' => (string) config('database.connections.'.config('database.default').'.database'),
            'user' => ['id' => $user->id, 'username' => $user->username, 'display_name' => $user->display_name],
            'arm_minutes' => self::ARM_MINUTES,
            'confirmation_phrases' => [
                'FULL' => 'RESET ALL DEVELOPMENT DATA',
                'TEST_ONLY' => 'RESET TEST CUSTOMERS',
            ],
            'arms' => [
                'FULL' => $this->publicArmState($user, self::MODE_FULL),
                'TEST_ONLY' => $this->publicArmState($user, self::MODE_TEST),
            ],
        ];

        if (! $allowed) {
            $payload['full_preview'] = [];
            $payload['test_preview'] = [];
            $payload['test_customers'] = [];
            return response()->json($payload);
        }

        $payload['full_preview'] = $reset->preview();
        $payload['test_preview'] = $reset->previewTest();
        $payload['test_customers'] = $reset->testCustomers();

        return response()->json($payload);
    }

    public function arm(Request $request): JsonResponse
    {
        $user = $this->assertEligible($request);
        $data = $request->validate([
            'mode' => ['required', 'in:FULL,TEST_ONLY'],
            'access_code' => ['required', 'string'],
            'confirmation_phrase' => ['required', 'string'],
        ]);

        $mode = strtoupper((string) $data['mode']);
        $this->assertConfirmationPhrase($mode, (string) $data['confirmation_phrase']);
        $this->assertAccessCode($user, (string) $data['access_code']);

        $now = Carbon::now();
        $expiresAt = $now->copy()->addMinutes(self::ARM_MINUTES);
        $nonce = (string) Str::uuid();

        Storage::disk(config('filesystems.state_disk'))->put($this->armFile($user, $mode), json_encode([
            'nonce' => $nonce,
            'mode' => $mode,
            'user_id' => $user->id,
            'username' => $user->username,
            'armed_at' => $now->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
            'environment' => app()->environment(),
            'database' => (string) config('database.connections.'.config('database.default').'.database'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return response()->json([
            'message' => $mode === self::MODE_FULL ? 'Full development reset armed.' : 'Test-customer reset armed.',
            'mode' => $mode,
            'arm_token' => $nonce,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    public function execute(Request $request, DevelopmentResetService $reset): JsonResponse
    {
        $user = $this->assertEligible($request);
        $data = $request->validate([
            'mode' => ['required', 'in:FULL,TEST_ONLY'],
            'arm_token' => ['required', 'string'],
            'access_code' => ['required', 'string'],
            'confirmation_phrase' => ['required', 'string'],
        ]);

        $mode = strtoupper((string) $data['mode']);
        $this->assertConfirmationPhrase($mode, (string) $data['confirmation_phrase']);
        $this->assertAccessCode($user, (string) $data['access_code']);
        $arm = $this->readValidatedArm($user, $mode, (string) $data['arm_token']);

        try {
            $deleted = $mode === self::MODE_FULL ? $reset->execute() : $reset->executeTest();
        } finally {
            Storage::disk(config('filesystems.state_disk'))->delete($this->armFile($user, $mode));
        }

        return response()->json([
            'message' => $mode === self::MODE_FULL ? 'Full development reset completed.' : 'Test-customer reset completed.',
            'mode' => $mode,
            'armed_at' => $arm['armed_at'] ?? null,
            'deleted' => $deleted,
            'total_deleted' => (int) collect($deleted)->sum(),
        ]);
    }

    public function cancel(Request $request): JsonResponse
    {
        $user = $this->assertSuperAdmin($request);
        $data = $request->validate(['mode' => ['required', 'in:FULL,TEST_ONLY']]);
        $mode = strtoupper((string) $data['mode']);
        Storage::disk(config('filesystems.state_disk'))->delete($this->armFile($user, $mode));

        return response()->json(['message' => 'Development reset arm cancelled.', 'mode' => $mode]);
    }

    private function assertEligible(Request $request): User
    {
        $user = $this->assertSuperAdmin($request);
        abort_unless($this->isAllowedEnvironment(), 403, 'Development Reset is available only in the local/development environment.');

        return $user;
    }

    private function assertSuperAdmin(Request $request): User
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_unless($user && $user->isActive() && $user->isSuperAdmin(), 403, 'Only an active Super Admin can access Development Reset.');

        return $user;
    }

    private function assertAccessCode(User $user, string $accessCode): void
    {
        abort_unless($accessCode !== '' && Hash::check($accessCode, $user->password), 422, 'Invalid Super Admin Access Code.');
    }

    private function assertConfirmationPhrase(string $mode, string $phrase): void
    {
        $expected = $mode === self::MODE_FULL ? 'RESET ALL DEVELOPMENT DATA' : 'RESET TEST CUSTOMERS';
        abort_unless(trim($phrase) === $expected, 422, 'The confirmation phrase does not match.');
    }

    private function readValidatedArm(User $user, string $mode, string $token): array
    {
        $file = $this->armFile($user, $mode);
        abort_unless(Storage::disk(config('filesystems.state_disk'))->exists($file), 422, 'This reset is not armed. Preview and arm it again.');

        $arm = json_decode((string) Storage::disk(config('filesystems.state_disk'))->get($file), true);
        if (! is_array($arm)) {
            Storage::disk(config('filesystems.state_disk'))->delete($file);
            abort(422, 'The reset arm state is invalid. Arm it again.');
        }

        $expiresAt = Carbon::parse((string) ($arm['expires_at'] ?? '1970-01-01'));
        if (Carbon::now()->greaterThan($expiresAt)) {
            Storage::disk(config('filesystems.state_disk'))->delete($file);
            abort(422, 'The reset arm window expired. Arm it again.');
        }

        $database = (string) config('database.connections.'.config('database.default').'.database');
        $valid = hash_equals((string) ($arm['nonce'] ?? ''), $token)
            && (int) ($arm['user_id'] ?? 0) === (int) $user->id
            && (string) ($arm['mode'] ?? '') === $mode
            && (string) ($arm['environment'] ?? '') === app()->environment()
            && (string) ($arm['database'] ?? '') === $database;

        if (! $valid) {
            Storage::disk(config('filesystems.state_disk'))->delete($file);
            abort(422, 'The reset arm state no longer matches this session/environment. Arm it again.');
        }

        return $arm;
    }

    private function publicArmState(User $user, string $mode): ?array
    {
        $file = $this->armFile($user, $mode);
        if (! Storage::disk(config('filesystems.state_disk'))->exists($file)) return null;
        $arm = json_decode((string) Storage::disk(config('filesystems.state_disk'))->get($file), true);
        if (! is_array($arm)) {
            Storage::disk(config('filesystems.state_disk'))->delete($file);
            return null;
        }
        $expiresAt = Carbon::parse((string) ($arm['expires_at'] ?? '1970-01-01'));
        if (Carbon::now()->greaterThan($expiresAt)) {
            Storage::disk(config('filesystems.state_disk'))->delete($file);
            return null;
        }

        return [
            'mode' => $mode,
            'armed_at' => $arm['armed_at'] ?? null,
            'expires_at' => $arm['expires_at'] ?? null,
        ];
    }

    private function armFile(User $user, string $mode): string
    {
        $suffix = strtolower(str_replace('_', '-', $mode));
        return 'private/yodamen-devreset-ui-'.$suffix.'-'.$user->id.'.json';
    }

    private function isAllowedEnvironment(): bool
    {
        return in_array(app()->environment(), ['local', 'development'], true);
    }
}
