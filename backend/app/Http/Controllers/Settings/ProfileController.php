<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information, including an optional new
     * avatar. The old avatar file (if any) is deleted from storage when
     * replaced — getRawOriginal() bypasses the accessor that resolves the
     * stored path to a full URL, since we need the raw path to delete it.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();

        $user->fill($request->safe()->except('avatar'));

        if ($request->hasFile('avatar')) {
            $previousAvatarPath = $user->getRawOriginal('avatar');

            $user->avatar = $request->file('avatar')->store('avatars', 'public');

            if ($previousAvatarPath) {
                Storage::disk('public')->delete($previousAvatarPath);
            }
        }

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Removes the user's avatar, reverting the UI back to their initials.
     * A separate lightweight action (rather than folded into update())
     * since clearing a photo shouldn't require resubmitting name/email.
     */
    public function destroyAvatar(Request $request): RedirectResponse
    {
        $user = $request->user();
        $previousAvatarPath = $user->getRawOriginal('avatar');

        if ($previousAvatarPath) {
            Storage::disk('public')->delete($previousAvatarPath);
            $user->update(['avatar' => null]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile photo removed.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
