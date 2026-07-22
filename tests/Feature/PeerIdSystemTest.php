<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PeerIdSystemTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
    }

    public function test_user_creation_auto_generates_sequential_peer_ids(): void
    {
        $user1 = User::query()->create([
            'first_name' => 'First',
            'last_name' => 'User',
            'email' => 'first@example.com',
        ]);

        $user2 = User::query()->create([
            'first_name' => 'Second',
            'last_name' => 'User',
            'email' => 'second@example.com',
        ]);

        $user3 = User::query()->create([
            'first_name' => 'Third',
            'last_name' => 'User',
            'email' => 'third@example.com',
        ]);

        $this->assertSame('PG31827361', $user1->peer_id);
        $this->assertSame('PG31827362', $user2->peer_id);
        $this->assertSame('PG31827363', $user3->peer_id);
    }

    public function test_peer_id_is_immutable_on_update(): void
    {
        $user = User::query()->create([
            'first_name' => 'Original',
            'email' => 'original@example.com',
        ]);

        $originalPeerId = $user->peer_id;
        $this->assertNotEmpty($originalPeerId);

        $user->peer_id = 'PG99999999';
        $user->first_name = 'Updated';
        $user->save();

        $this->assertSame($originalPeerId, $user->fresh()->peer_id);
    }

    public function test_profile_api_returns_peer_id(): void
    {
        $user = User::query()->create([
            'first_name' => 'Profile',
            'email' => 'profile@example.com',
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/profile')->assertOk();
        $response->assertJsonPath('data.peer_id', $user->peer_id);

        $meResponse = $this->getJson('/api/v1/auth/me')->assertOk();
        $meResponse->assertJsonPath('data.peer_id', $user->peer_id);
    }

    public function test_members_listing_api_returns_peer_id(): void
    {
        $authUser = User::query()->create([
            'first_name' => 'Auth',
            'email' => 'auth@example.com',
            'status' => 'active',
        ]);

        $member = User::query()->create([
            'first_name' => 'Member',
            'email' => 'member@example.com',
            'status' => 'active',
        ]);

        $this->assertSame('PG31827361', $authUser->peer_id);
        $this->assertSame('PG31827362', $member->peer_id);

        Sanctum::actingAs($authUser);

        $response = $this->getJson('/api/v1/members?per_page=10')->assertOk();

        $memberData = collect($response->json('data'))->firstWhere('id', $member->id);
        $this->assertNotNull($memberData);
        $this->assertSame($member->peer_id, $memberData['peer_id']);
    }

    private function createTestSchema(): void
    {
        Schema::dropIfExists('posts');
        Schema::dropIfExists('user_links');
        Schema::dropIfExists('sme_business_story_submissions');
        Schema::dropIfExists('circle_subscriptions');
        Schema::dropIfExists('circle_members');
        Schema::dropIfExists('circles');
        Schema::dropIfExists('peer_blocks');
        Schema::dropIfExists('user_follows');
        Schema::dropIfExists('cities');
        Schema::dropIfExists('connections');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('peer_id')->nullable()->unique();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('display_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('company_name')->nullable();
            $table->string('designation')->nullable();
            $table->string('business_type')->nullable();
            $table->string('status')->nullable();
            $table->string('membership_status')->nullable();
            $table->string('profile_visibility')->nullable();
            $table->string('contact_visibility')->nullable();
            $table->string('city_of_residence')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->string('business_city')->nullable();
            $table->string('business_state')->nullable();
            $table->string('business_country')->nullable();
            $table->string('business_pincode')->nullable();
            $table->integer('main_business_category_id')->nullable();
            $table->integer('business_category_id')->nullable();
            $table->string('business_sub_category')->nullable();
            $table->string('company_type')->nullable();
            $table->integer('year_of_establishment')->nullable();
            $table->string('annual_revenue_range')->nullable();
            $table->string('number_of_employees')->nullable();
            $table->text('products_services_offered')->nullable();
            $table->json('business_keywords')->nullable();
            $table->integer('experience_years')->nullable();
            $table->text('experience_summary')->nullable();
            $table->json('skills')->nullable();
            $table->json('industries_of_interest')->nullable();
            $table->json('interests')->nullable();
            $table->json('collaboration_goals')->nullable();
            $table->json('i_can_help_with')->nullable();
            $table->json('i_am_looking_for')->nullable();
            $table->text('superpower')->nullable();
            $table->string('preferred_language')->nullable();
            $table->string('preferred_meeting_format')->nullable();
            $table->boolean('willing_to_mentor')->nullable();
            $table->boolean('open_to_cross_city_collaboration')->nullable();
            $table->boolean('open_to_speaking_at_events')->nullable();
            $table->string('business_website')->nullable();
            $table->string('linkedin_profile')->nullable();
            $table->string('instagram_handle')->nullable();
            $table->string('facebook_profile')->nullable();
            $table->string('youtube_channel')->nullable();
            $table->uuid('cover_photo_file_id')->nullable();
            $table->uuid('profile_video_id')->nullable();
            $table->integer('coins_balance')->default(0);
            $table->integer('life_impacted_count')->default(0);
            $table->timestamp('last_login_at')->nullable();
            $table->string('public_profile_slug')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('cities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
        });

        Schema::create('user_follows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('follower_id');
            $table->uuid('following_id');
            $table->timestamps();
        });

        Schema::create('peer_blocks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('blocker_user_id');
            $table->uuid('blocked_user_id');
            $table->string('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('circles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->nullable();
        });

        Schema::create('circle_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('circle_id');
            $table->string('status')->nullable();
            $table->string('role')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamp('paid_starts_at')->nullable();
            $table->timestamp('paid_ends_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->string('joined_via')->nullable();
            $table->string('payment_status')->nullable();
            $table->string('zoho_addon_code')->nullable();
            $table->string('addon_name')->nullable();
            $table->uuid('circle_subscription_id')->nullable();
            $table->string('subscription_status')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('circle_subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable();
            $table->uuid('circle_id')->nullable();
            $table->string('zoho_addon_code')->nullable();
            $table->string('zoho_addon_name')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('connections', function (Blueprint $table): void {
            $table->uuid('requester_id');
            $table->uuid('addressee_id');
            $table->boolean('is_approved')->default(false);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->primary(['requester_id', 'addressee_id']);
        });

        Schema::create('sme_business_story_submissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('status')->nullable();
            $table->string('story_link')->nullable();
            $table->timestamps();
        });

        Schema::create('user_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('title')->nullable();
            $table->string('url')->nullable();
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('title')->nullable();
            $table->text('content')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
}
