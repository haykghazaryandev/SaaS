<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Profile photos live in the database (320×320 WebP, ~15 KB): the server disk is wiped on every deploy.
 * Photos already on the local disk are moved in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_photos', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('token', 16);          // part of the URL: a new photo gets a new URL (browser cache)
            $table->text('data');                 // base64 WebP
            $table->timestamps();
        });

        foreach (DB::table('users')->whereNotNull('avatar_path')->get(['id', 'avatar_path']) as $u) {
            $disk = Storage::disk('public');
            if (preg_match('#^avatars/\d+-([A-Za-z0-9]+)\.webp$#', $u->avatar_path, $m) && $disk->exists($u->avatar_path)) {
                DB::table('user_photos')->insert(['user_id' => $u->id, 'token' => $m[1], 'data' => base64_encode($disk->get($u->avatar_path)), 'created_at' => now(), 'updated_at' => now()]);
            } else {
                DB::table('users')->where('id', $u->id)->update(['avatar_path' => null]);   // the file is gone
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_photos');
    }
};
