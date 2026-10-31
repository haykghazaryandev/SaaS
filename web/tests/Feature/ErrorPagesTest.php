<?php

use App\Models\User;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

// 404 and 419 never show Laravel's bare pages (a page load: our Error page), and answer in the visitor's language (App\Exceptions\FriendlyErrors).
// Each check starts its last request in the default language, as a fresh request on a real server does.

// ---------- 404 ----------

it('shows our error page in the browser\'s language for an address that does not exist', function () {
    app()->setLocale('hy');
    $this->get('/no-such-page', ['Accept-Language' => 'ru'])->assertNotFound()
        ->assertInertia(fn (Assert $p) => $p->component('Error')->where('status', 404)->where('locale', 'ru'));
});

it('uses the account\'s language, also for a record that is gone', function () {
    $this->actingAs(User::factory()->create(['locale' => 'en', 'locale_chosen_at' => now()]));
    // the route binding fails before the language middleware runs
    app()->setLocale('hy');
    $this->get('/games/999999')->assertNotFound()
        ->assertInertia(fn (Assert $p) => $p->component('Error')->where('status', 404)->where('locale', 'en')->has('langVersion'));
});

it('opens a missing page inside the app as our error page, and answers JSON with a message', function () {
    $this->actingAs(User::factory()->create(['locale' => 'en', 'locale_chosen_at' => now()]));
    $this->get('/games/999999', ['X-Inertia' => 'true'])->assertNotFound()->assertJsonPath('component', 'Error');
    app()->setLocale('hy');
    $this->getJson('/games/999999')->assertNotFound()->assertJson(['message' => trans('common.not_found', [], 'en')]);
});

it('keeps a form sent to a record that is gone on its page, with a toast', function () {
    $this->actingAs(User::factory()->create(['locale' => 'en', 'locale_chosen_at' => now()]));
    app()->setLocale('hy');
    $this->from('/friends')->delete('/games/999999', [], ['X-Inertia' => 'true'])
        ->assertStatus(303)->assertRedirect('/friends')->assertSessionHasErrors(['error' => trans('common.not_found', [], 'en')]);
});

// ---------- 419 (a form open too long) ----------

beforeEach(function () {
    // the CSRF check is off in tests: a route that fails the same way
    Route::middleware('web')->match(['post', 'delete'], '/_test/expired', fn () => throw new TokenMismatchException);
});

it('sends an expired app form back to its page, refreshed, with a toast in the visitor\'s language', function () {
    $this->get('/login?lang=en');
    app()->setLocale('hy');
    $this->from('/login')->post('/_test/expired', [], ['X-Inertia' => 'true'])
        ->assertRedirect('/login')->assertSessionHas('error', trans('common.page_expired', [], 'en'));
});

it('does the same for a plain form, and answers JSON with a message', function () {
    $this->withSession(['locale' => 'ru']);
    app()->setLocale('hy');
    $this->from('/login')->post('/_test/expired')->assertRedirect('/login')->assertSessionHas('error', trans('common.page_expired', [], 'ru'));
    app()->setLocale('hy');
    $this->postJson('/_test/expired', [], ['Accept-Language' => 'en'])->assertStatus(419)->assertJson(['message' => trans('common.page_expired', [], 'ru')]);
});

it('sends an expired DELETE back with 303, so the browser comes back with a GET', function () {
    $this->from('/login')->delete('/_test/expired', [], ['X-Inertia' => 'true'])->assertStatus(303)->assertRedirect('/login');
});

it('leaves the admin panel (Livewire) to its own handling', function () {
    // Livewire reacts to the 419 status itself ("This page has expired, refresh?"): not turned into a redirect
    $this->post('/_test/expired', [], ['X-Livewire' => 'true'])->assertStatus(419);
});

it('answers 404 (not 405) to a form sent to an address that does not exist', function () {
    $this->post('/no-such-page')->assertNotFound();
    $this->delete('/no-such-page')->assertNotFound();
});
