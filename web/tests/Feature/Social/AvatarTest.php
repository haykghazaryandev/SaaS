<?php

use App\Models\User;
use App\Models\UserPhoto;
use Illuminate\Http\UploadedFile;

it('saves a profile photo as a small square and shows it everywhere', function () {
    $u = User::factory()->create();
    $this->actingAs($u)->post('/me/avatar', ['photo' => UploadedFile::fake()->image('me.jpg', 900, 600)])->assertSessionHasNoErrors();
    $u->refresh();
    expect($u->avatar_path)->toStartWith('avatars/')->toEndWith('.webp');
    // stored in the database (survives deploys) and served at its URL, cacheable for good
    $res = $this->get($u->avatarUrl())->assertOk()->assertHeader('Content-Type', 'image/webp');
    expect($res->headers->get('Cache-Control'))->toContain('immutable');
    [$w, $h] = getimagesizefromstring($res->getContent());
    expect([$w, $h])->toBe([320, 320]);
    // the shared user prop carries the photo URL
    $this->get('/')->assertInertia(fn ($page) => $page->where('auth.user.avatar', $u->avatarUrl()));
});

it('replaces the old photo and can remove it', function () {
    $u = User::factory()->create();
    $this->actingAs($u)->post('/me/avatar', ['photo' => UploadedFile::fake()->image('a.png', 200, 200)]);
    $old = $u->fresh()->avatarUrl();
    $this->actingAs($u)->post('/me/avatar', ['photo' => UploadedFile::fake()->image('b.png', 200, 200)]);
    $this->get($old)->assertNotFound();   // a new photo gets a new URL
    $this->get($u->fresh()->avatarUrl())->assertOk();
    expect(UserPhoto::count())->toBe(1);
    $this->actingAs($u)->delete('/me/avatar')->assertRedirect();
    expect($u->fresh()->avatar_path)->toBeNull()->and(UserPhoto::count())->toBe(0);
});

it('refuses files that are not photos', function () {
    $u = User::factory()->create();
    $this->actingAs($u)->post('/me/avatar', ['photo' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertSessionHasErrors('photo');
    $this->post('/logout');
    $this->post('/me/avatar', ['photo' => UploadedFile::fake()->image('a.png')])->assertRedirect('/login');
});
