<?php

use App\Models\User;
use Illuminate\Support\Facades\Mail;

// a route's request limit (throttle:…) never shows Laravel's bare "429 Too Many Requests", and it answers in the
// visitor's language (App\Exceptions\FriendlyErrors)

beforeEach(function () {
    Mail::fake();
    $this->hit = function (array $headers = []) {
        // "Forgot password?" allows 5 tries a minute: the 6th is over the limit
        foreach (range(1, 5) as $i) {
            $this->withHeaders($headers)->from('/login')->post('/login/forgot', ['email' => 'ani@mail.am']);
        }
        // a real server starts every request in the default language (tests share one app between requests)
        app()->setLocale('hy');

        return $this->withHeaders($headers)->from('/login')->post('/login/forgot', ['email' => 'ani@mail.am']);
    };
});

it('keeps an app form on its page with a message (a toast) in the chosen language', function () {
    $this->get('/login?lang=en');
    ($this->hit)(['X-Inertia' => 'true'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['throttle' => trans('common.too_many', ['min' => 1], 'en')]);
});

it('answers a JSON request with the message in the browser\'s language', function () {
    ($this->hit)(['Accept' => 'application/json', 'Accept-Language' => 'ru'])
        ->assertStatus(429)->assertJson(['message' => trans('common.too_many', ['min' => 1], 'ru')]);
});

it('shows a page in the app\'s look, in the visitor\'s language, on a full page load', function () {
    $this->withSession(['locale' => 'ru']);
    ($this->hit)()->assertStatus(429)
        ->assertSee('<html lang="ru">', false)->assertSee('Подождите немного')->assertSee('Попробовать снова')
        ->assertDontSee('Too Many Requests');
});

it('uses a logged-in player\'s language', function () {
    $this->actingAs(User::factory()->create(['locale' => 'en', 'locale_chosen_at' => now()]));
    // a profile photo allows 10 uploads a minute
    foreach (range(1, 10) as $i) {
        $this->postJson('/me/avatar', []);
    }
    app()->setLocale('hy');
    $this->postJson('/me/avatar', [])->assertStatus(429)->assertJson(['message' => trans('common.too_many', ['min' => 1], 'en')]);
});
