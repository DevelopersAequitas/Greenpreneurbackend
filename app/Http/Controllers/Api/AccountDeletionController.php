<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Mail\AccountDeletionRequestedMail;
use App\Models\AccountDeletionRequest;
use App\Models\UserPushToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * API controller for account deletion request submission.
 *
 * Architecture note:
 * This project uses a request-based deletion workflow:
 *   1. User submits a deletion request via this API (status = 'pending').
 *   2. Admin reviews the request in the admin panel.
 *   3. Admin approves → user is soft-deleted + AccountDeletedMail sent.
 *   4. Admin rejects → request status set to 'rejected'.
 *
 * This API only handles step 1. The admin approval side (steps 2–4) is
 * handled by App\Http\Controllers\Admin\AccountDeletionController and must
 * not be duplicated or bypassed here.
 *
 * The deletion-status endpoint (GET /api/account-deletion-status) is handled
 * by App\Http\Controllers\AccountDeletionController@status and is not
 * duplicated here.
 */
class AccountDeletionController extends BaseApiController
{
    /**
     * Submit an account deletion request for the currently authenticated user.
     *
     * POST /api/v1/auth/request-account-deletion
     *
     * - Requires Sanctum authentication + unity.user middleware.
     * - Uses $request->user() only; never accepts a user_id from the client.
     * - Creates an AccountDeletionRequest (status = 'pending') if one does not
     *   already exist for this user.
     * - Sends AccountDeletionRequestedMail to the user's registered email.
     * - Does NOT revoke Sanctum tokens or soft-delete the user at this stage;
     *   those actions are performed by the admin when the request is approved.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        /** @var \App\Models\User $user */
        $user = $request->user();

        try {
            // Prevent duplicate pending requests for the same user.
            $existingPending = AccountDeletionRequest::where('user_id', $user->id)
                ->where('status', 'pending')
                ->first();

            if ($existingPending) {
                return $this->error(
                    'An account deletion request is already pending for your account.',
                    409,
                );
            }

            AccountDeletionRequest::create([
                'user_id' => $user->id,
                'email'   => $user->email,
                'reason'  => $request->input('reason'),
                'status'  => 'pending',
            ]);

            // Send confirmation email — failure must not abort the request.
            try {
                Mail::to($user->email)->send(new AccountDeletionRequestedMail($user));
            } catch (Throwable $mailException) {
                Log::error('api.account-deletion.mail_failed', [
                    'user_id'   => $user->id,
                    'error'     => $mailException->getMessage(),
                    'exception' => $mailException,
                ]);
            }

            Log::info('api.account-deletion.request_submitted', [
                'user_id' => $user->id,
            ]);

            return $this->success(
                null,
                'Your account deletion request has been submitted. Our team will review and process it shortly.',
            );

        } catch (Throwable $e) {
            report($e);

            Log::error('api.account-deletion.store_failed', [
                'user_id'   => $user->id,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);

            return $this->error('Unable to submit account deletion request. Please try again later.', 500);
        }
    }
}
