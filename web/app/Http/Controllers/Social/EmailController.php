<?php

namespace App\Http\Controllers\Social;

use App\Domain\Auth\EmailVerification;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** "Your email": add or change it (a code goes to the new address; it is saved when the code is right). See EmailVerification. */
class EmailController extends Controller
{
    public function __construct(private EmailVerification $verification) {}

    public function show(Request $request)
    {
        $u = $request->user();
        $pending = $this->verification->pendingChange($u);

        return Inertia::render('profile/Email', [
            'email' => $u->email,
            'confirmed' => $u->email && $u->email_verified_at !== null,
            'pending' => $pending,
            'devCode' => EmailVerification::devCode($pending),
        ]);
    }

    /** A new address (or the account's own unconfirmed one): a code goes to it. */
    public function change(Request $request)
    {
        $data = $request->validate(['email' => 'required|email:rfc|max:120']);
        $this->verification->startChange($request->user(), $data['email'], (string) $request->ip());

        return redirect()->route('me.email')->with('success', __('profile.email.sent'));
    }

    public function send(Request $request)
    {
        $u = $request->user();
        $email = $this->verification->pendingChange($u);
        if (! $email) {
            return redirect()->route('me.email');
        }
        $this->verification->startChange($u, $email, (string) $request->ip());

        return back()->with('success', __('profile.email.sent'));
    }

    public function verify(Request $request)
    {
        $data = $request->validate(['code' => 'required|digits:4']);
        $this->verification->finishChange($request->user(), $data['code']);

        return redirect()->route('me.profile')->with('success', __('profile.email.done'));
    }
}
