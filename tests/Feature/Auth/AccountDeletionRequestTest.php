<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Mail\AccountDeletionRequestedMail;
use App\Models\AccountDeletionRequest;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature tests for POST /api/v1/auth/request-account-deletion
 *
 * Tests the request-submission step of the account deletion workflow.
 * The admin-approval side (soft-delete, token revocation) is handled
 * separately by Admin\AccountDeletionController and is not tested here.
 */
class AccountDeletionRequestTest extends TestCase
{
    use DatabaseTransactions;

    private const ENDPOINT = '/api/v1/auth/request-account-deletion';
    private const STATUS_ENDPOINT = '/api/account-deletion-status';

    // -------------------------------------------------------------------------
    // Schema helpers
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchemas();
    }

    private function createTestSchemas(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('account_deletion_requests');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('first_name', 100);
            $table->string('last_name', 100)->nullable();
            $table->string('display_name', 150)->nullable();
            $table->string('email', 255)->unique();
            $table->string('phone', 20)->nullable()->unique();
            $table->string('password_hash')->nullable();
            $table->string('status', 50)->default('active');
            $table->string('membership_status', 50)->default('visitor');
            $table->timestamp('membership_expiry')->nullable();
            $table->timestamp('membership_starts_at')->nullable();
            $table->timestamp('membership_ends_at')->nullable();
            $table->string('public_profile_slug', 80)->nullable()->unique();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('tokenable_type');
            $table->uuid('tokenable_id');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['tokenable_type', 'tokenable_id']);
        });

        Schema::create('account_deletion_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->string('email', 255)->nullable();
            $table->text('reason')->nullable();
            $table->string('status', 50)->default('pending');
            $table->timestamps();
        });
    }

    // -------------------------------------------------------------------------
    // Factories / helpers
    // -------------------------------------------------------------------------

    private function makeUser(array $overrides = []): User
    {
        $user = new User(array_merge([
            'first_name'        => 'Test',
            'last_name'         => 'User',
            'email'             => 'test-' . Str::random(6) . '@example.com',
            'phone'             => '9' . rand(100000000, 999999999),
            'status'            => 'active',
            'membership_status' => 'visitor',
        ], $overrides));
        $user->id = (string) Str::uuid();
        $user->save();

        return $user;
    }

    // -------------------------------------------------------------------------
    // Test 1 — Unauthenticated request is rejected
    // -------------------------------------------------------------------------

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->postJson(self::ENDPOINT);

        $response->assertStatus(401);
    }

    // -------------------------------------------------------------------------
    // Test 2 — Authenticated user can submit deletion request
    // -------------------------------------------------------------------------

    public function test_authenticated_user_can_submit_deletion_request(): void
    {
        Mail::fake();

        $user = $this->makeUser();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(self::ENDPOINT, ['reason' => 'I no longer need this account.']);

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'message', 'data'])
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('account_deletion_requests', [
            'user_id' => $user->id,
            'status'  => 'pending',
        ]);
    }

    // -------------------------------------------------------------------------
    // Test 3 — Only the authenticated user's account is targeted
    // -------------------------------------------------------------------------

    public function test_only_authenticated_user_account_is_targeted(): void
    {
        Mail::fake();

        $requester = $this->makeUser();
        $otherUser = $this->makeUser();

        $this->actingAs($requester, 'sanctum')
            ->postJson(self::ENDPOINT, ['reason' => 'Want to leave.']);

        // The deletion request must be for the requester, NOT the other user.
        $this->assertDatabaseHas('account_deletion_requests', [
            'user_id' => $requester->id,
            'status'  => 'pending',
        ]);

        $this->assertDatabaseMissing('account_deletion_requests', [
            'user_id' => $otherUser->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // Test 4 — Duplicate pending request is rejected with 409
    // -------------------------------------------------------------------------

    public function test_duplicate_pending_request_returns_409(): void
    {
        Mail::fake();

        $user = $this->makeUser();

        // First submission.
        $this->actingAs($user, 'sanctum')
            ->postJson(self::ENDPOINT, ['reason' => 'First request.'])
            ->assertStatus(200);

        // Second submission while first is still pending.
        $response = $this->actingAs($user, 'sanctum')
            ->postJson(self::ENDPOINT, ['reason' => 'Second request.']);

        $response->assertStatus(409)
            ->assertJson(['success' => false]);

        // Still only one row in the DB.
        $this->assertSame(1, AccountDeletionRequest::where('user_id', $user->id)->count());
    }

    // -------------------------------------------------------------------------
    // Test 5 — Status endpoint reflects pending request
    // -------------------------------------------------------------------------

    public function test_status_endpoint_reflects_pending_request(): void
    {
        Mail::fake();

        $user = $this->makeUser();

        $this->actingAs($user, 'sanctum')
            ->postJson(self::ENDPOINT, ['reason' => 'Testing status endpoint.'])
            ->assertStatus(200);

        $statusResponse = $this->actingAs($user, 'sanctum')
            ->getJson(self::STATUS_ENDPOINT);

        $statusResponse->assertStatus(200);

        $body = $statusResponse->json();
        $this->assertSame('pending', $body['status'] ?? null);
    }

    // -------------------------------------------------------------------------
    // Test 6 — Confirmation email is sent
    // -------------------------------------------------------------------------

    public function test_confirmation_email_is_sent_on_successful_request(): void
    {
        Mail::fake();

        $user = $this->makeUser();

        $this->actingAs($user, 'sanctum')
            ->postJson(self::ENDPOINT, ['reason' => 'Leaving the platform.'])
            ->assertStatus(200);

        Mail::assertSent(AccountDeletionRequestedMail::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email);
        });
    }

    // -------------------------------------------------------------------------
    // Test 7 — Reason is optional (null body accepted)
    // -------------------------------------------------------------------------

    public function test_reason_is_optional(): void
    {
        Mail::fake();

        $user = $this->makeUser();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(self::ENDPOINT);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('account_deletion_requests', [
            'user_id' => $user->id,
            'status'  => 'pending',
        ]);
    }

    // -------------------------------------------------------------------------
    // Test 8 — Reason exceeding 1000 characters fails validation
    // -------------------------------------------------------------------------

    public function test_reason_too_long_fails_validation(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(self::ENDPOINT, ['reason' => str_repeat('a', 1001)]);

        $response->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // Test 9 — Admin workflow: admin can see the request
    // -------------------------------------------------------------------------

    public function test_submitted_request_is_visible_in_account_deletion_requests_table(): void
    {
        Mail::fake();

        $user = $this->makeUser();

        $this->actingAs($user, 'sanctum')
            ->postJson(self::ENDPOINT, ['reason' => 'Admin should see this.'])
            ->assertStatus(200);

        $request = AccountDeletionRequest::where('user_id', $user->id)->first();

        $this->assertNotNull($request);
        $this->assertSame('pending', $request->status);
        $this->assertSame($user->id, $request->user_id);
        $this->assertSame($user->email, $request->email);
    }

    // -------------------------------------------------------------------------
    // Test 10 — deleted_at is NOT set at request-submission time
    //           (soft-delete only happens when admin approves)
    // -------------------------------------------------------------------------

    public function test_user_is_not_soft_deleted_when_request_is_submitted(): void
    {
        Mail::fake();

        $user = $this->makeUser();

        $this->actingAs($user, 'sanctum')
            ->postJson(self::ENDPOINT, ['reason' => 'Testing soft delete timing.'])
            ->assertStatus(200);

        $fresh = User::withTrashed()->find($user->id);

        $this->assertNotNull($fresh, 'User record should still exist.');
        $this->assertNull($fresh->deleted_at, 'deleted_at must be NULL at request-submission time.');
    }

    // -------------------------------------------------------------------------
    // Test 11 — Sanctum tokens remain valid at request-submission time
    //           (tokens revoked only when admin approves the request)
    // -------------------------------------------------------------------------

    public function test_sanctum_token_is_not_revoked_after_submitting_request(): void
    {
        Mail::fake();

        $user  = $this->makeUser();
        $user->createToken('auth_token');

        $tokenCountBefore = \DB::table('personal_access_tokens')
            ->where('tokenable_id', $user->id)
            ->count();

        $this->actingAs($user, 'sanctum')
            ->postJson(self::ENDPOINT, ['reason' => 'Token should still work.'])
            ->assertStatus(200);

        // The personal_access_tokens count must be unchanged — no tokens were revoked.
        $tokenCountAfter = \DB::table('personal_access_tokens')
            ->where('tokenable_id', $user->id)
            ->count();

        $this->assertSame(
            $tokenCountBefore,
            $tokenCountAfter,
            'Sanctum tokens must NOT be revoked when a deletion request is submitted.',
        );
    }
}
