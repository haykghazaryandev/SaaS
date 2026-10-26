<?php

namespace App\Domain\Club;

use App\Domain\Booking\BookingException;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** The place's shop: products with a photo, price and discount; sales recorded at the desk. */
class Shop
{
    public const PHOTO = 640;

    public function __construct(private Money $money) {}

    public function save(Product $product, array $data, ?UploadedFile $photo = null): Product
    {
        if (! $product->exists && ! $product->venue_id) {
            throw new BookingException('feature_off');
        }
        $product->fill([
            'name' => trim($data['name']), 'description' => $data['description'] ?? null, 'price' => (int) $data['price'],
            'discount_pct' => (int) ($data['discount_pct'] ?? 0), 'in_stock' => (bool) ($data['in_stock'] ?? true), 'active' => (bool) ($data['active'] ?? true),
        ]);
        if ($photo) {
            $product->photo = $this->webp($photo);
            $product->photo_token = Str::random(8);
        }
        $product->save();

        return $product;
    }

    /** Sold at the desk: the product's discount, then the player's card discount; by card or cash. */
    public function sell(Venue $venue, Product $product, int $qty, string $method, User $by, ?User $player = null): Sale
    {
        if ($product->venue_id !== $venue->id || $qty < 1 || $qty > 100) {
            throw new BookingException('bad_amount');
        }
        if (! $product->in_stock) {
            throw new BookingException('out_of_stock');
        }

        return DB::transaction(function () use ($venue, $product, $qty, $method, $by, $player) {
            $payment = $this->money->charge($venue, $by, 'shop', $product->finalPrice() * $qty, $method, $player, null, $qty.' × '.$product->name);
            $sale = Sale::create(['venue_id' => $venue->id, 'product_id' => $product->id, 'product_name' => $product->name, 'user_id' => $player?->id,
                'qty' => $qty, 'price_each' => $product->finalPrice(), 'payment_id' => $payment->id, 'created_by' => $by->id]);
            $payment->update(['ref_id' => $sale->id]);

            return $sale;
        });
    }

    /** A photo as a WebP of at most 640 px (kept in the database: the server disk is wiped on deploys). */
    private function webp(UploadedFile $file): string
    {
        $img = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if (! $img) {
            throw new RuntimeException('bad_image');
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $k = min(1, self::PHOTO / max($w, $h));
        $out = imagecreatetruecolor(max(1, (int) ($w * $k)), max(1, (int) ($h * $k)));
        imagecopyresampled($out, $img, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);
        ob_start();
        imagewebp($out, null, 82);
        $data = (string) ob_get_clean();
        imagedestroy($img);
        imagedestroy($out);

        return base64_encode($data);
    }
}
