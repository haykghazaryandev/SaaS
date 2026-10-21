<?php

namespace App\Domain\Social;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Profile photo: the uploaded image is turned straight, cropped to the centre square and saved
 * as a small 320×320 WebP (public disk, avatars/). The original is never kept.
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

        $path = 'avatars/'.$user->id.'-'.Str::random(8).'.webp';
        Storage::disk('public')->put($path, $data);
        $this->delete($user);
        $user->forceFill(['avatar_path' => $path])->save();

        return $path;
    }

    public function delete(User $user): void
    {
        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->forceFill(['avatar_path' => null])->save();
        }
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
