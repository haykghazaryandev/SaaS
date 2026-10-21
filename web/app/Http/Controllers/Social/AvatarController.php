<?php

namespace App\Http\Controllers\Social;

use App\Domain\Social\AvatarPhoto;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Upload or remove the profile photo (My profile). */
class AvatarController extends Controller
{
    public function store(Request $request, AvatarPhoto $photos)
    {
        $request->validate(['photo' => 'required|image|mimes:jpeg,jpg,png,webp|max:8192|dimensions:min_width=64,min_height=64']);
        try {
            $photos->save($request->user(), $request->file('photo'));
        } catch (RuntimeException) {
            throw ValidationException::withMessages(['photo' => __('profile.photo.bad')]);
        }

        return back()->with('success', __('profile.photo.saved'));
    }

    public function destroy(Request $request, AvatarPhoto $photos)
    {
        $photos->delete($request->user());

        return back()->with('success', __('profile.photo.removed'));
    }
}
