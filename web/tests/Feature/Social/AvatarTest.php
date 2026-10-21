<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('public'));

it('saves a profile photo as a small square and shows it everywhere', function () {
    $u = User::factory()->create();
    $this->actingAs($u)->post('/me/avatar', ['photo' => UploadedFile::fake()->image('me.jpg', 900, 600)])->assertSessionHasNoErrors();
    $u->refresh();
    expect($u->avatar_path)->toStartWith('avatars/')->toEndWith('.webp');
    Storage::disk('public')->assertExists($u->avatar_path);
    [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($u->avatar_path));
    expect([$w, $h])->toBe([320, 320]);
    // the shared user prop carries the photo URL
    $this->get('/')->assertInertia(fn ($page) => $page->where('auth.user.avatar', $u->avatarUrl()));
});

it('replaces the old photo and can remove it', function () {
    $u = User::factory()->create();
    $this->actingAs($u)->post('/me/avatar', ['photo' => UploadedFile::fake()->image('a.png', 200, 200)]);
    $old = $u->fresh()->avatar_path;
    $this->actingAs($u)->post('/me/avatar', ['photo' => UploadedFile::fake()->image('b.png', 200, 200)]);
    Storage::disk('public')->assertMissing($old);
    $this->actingAs($u)->delete('/me/avatar')->assertRedirect();
    expect($u->fresh()->avatar_path)->toBeNull();
});

it('refuses files that are not photos', function () {
    $u = User::factory()->create();
    $this->actingAs($u)->post('/me/avatar', ['photo' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertSessionHasErrors('photo');
    $this->post('/logout');
    $this->post('/me/avatar', ['photo' => UploadedFile::fake()->image('a.png')])->assertRedirect('/login');
});
