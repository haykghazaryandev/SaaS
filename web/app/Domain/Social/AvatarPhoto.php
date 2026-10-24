<?php

namespace App\Domain\Social;

use App\Models\User;
use App\Models\UserPhoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Profile photo: the uploaded image is turned straight, cropped to the centre square and saved
 * as a small 320×320 WebP in the database (user_photos; the server disk is wiped on every deploy).
 * The original is never kept. users.avatar_path = "avatars/{id}-{token}.webp", the URL path.
 */
class AvatarPhoto
{
    public const SIZE = 320;

    public function save(User $user, UploadedFile $file): string
    {
        $raw = file_get_contents($file->getRealPath());
        $img = @imagecreatefromstring($raw);
        if (! $img) {
            throw new RuntimeException('bad_image');
        }
        $img = $this->upright($img, $file);
        $w = imagesx($img);
        $h = imagesy($img);
        $side = min($w, $h);
        $out = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagecopyresampled($out, $img, 0, 0, (int) (($w - $side) / 2), (int) (($h - $side) / 2), self::SIZE, self::SIZE, $side, $side);
        ob_start();
        imagewebp($out, null, 82);
        $data = ob_get_clean();
        imagedestroy($img);
        imagedestroy($out);

        $token = Str::random(8);
        UserPhoto::updateOrCreate(['user_id' => $user->id], ['token' => $token, 'data' => base64_encode($data)]);
        $path = 'avatars/'.$user->id.'-'.$token.'.webp';
        $user->forceFill(['avatar_path' => $path])->save();

        return $path;
    }

    public function delete(User $user): void
    {
        UserPhoto::where('user_id', $user->id)->delete();
        if ($user->avatar_path) {
            $user->forceFill(['avatar_path' => null])->save();
        }
    }

    /** The WebP bytes for /avatars/{id}-{token}.webp, or null. */
    public function find(string $file): ?string
    {
        if (! preg_match('/^(\d+)-([A-Za-z0-9]{1,16})\.webp$/', $file, $m)) {
            return null;
        }
        $photo = UserPhoto::where('user_id', (int) $m[1])->where('token', $m[2])->first();

        return $photo ? base64_decode($photo->data) : null;
    }

    /** Phone photos carry their rotation in EXIF: apply it so the face isn't sideways. */
    private function upright(\GdImage $img, UploadedFile $file): \GdImage
    {
        if (! function_exists('exif_read_data') || ! in_array(strtolower($file->getClientOriginalExtension()), ['jpg', 'jpeg'], true)) {
            return $img;
        }
        $o = (int) (@exif_read_data($file->getRealPath())['Orientation'] ?? 1);

        return match ($o) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => $img,
        };
    }
}
