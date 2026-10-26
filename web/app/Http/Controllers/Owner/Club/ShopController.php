<?php

namespace App\Http\Controllers\Owner\Club;

use App\Domain\Club\Shop;
use App\Http\Controllers\Owner\OwnerController;
use App\Models\Product;
use App\Models\Sale;
use App\Support\LocalTime;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Owner → Shop: products (photo, price, discount, in stock) and the sales; a quick cash sale without a card. */
class ShopController extends OwnerController
{
    public function index(Request $request)
    {
        $venue = $this->venue($request);
        $this->ensureAny($venue, ['shop']);

        return Inertia::render('owner/Shop', [
            'products' => Product::where('venue_id', $venue->id)->orderByDesc('active')->orderBy('sort')->orderBy('name')->get()->map(fn (Product $p) => [
                'id' => $p->id, 'name' => $p->name, 'description' => $p->description, 'price' => $p->price, 'final' => $p->finalPrice(),
                'discount_pct' => $p->discount_pct, 'in_stock' => $p->in_stock, 'active' => $p->active, 'photo' => $p->photoUrl(),
            ])->values(),
            'sales' => Sale::where('venue_id', $venue->id)->with('user:id,name', 'payment:id,amount,method')->latest('id')->limit(60)->get()->map(fn (Sale $s) => [
                // sold on a booking's bill: paid with the bill (no payment of its own)
                'id' => $s->id, 'what' => $s->qty.' × '.$s->product_name, 'who' => $s->user?->name,
                'amount' => $s->payment?->amount ?? $s->qty * $s->price_each, 'method' => $s->payment?->method ?? 'bill',
                'at' => $s->created_at->setTimezone(LocalTime::tz())->format('d.m H:i'),
            ])->values(),
        ]);
    }

    public function store(Request $request, Shop $shop)
    {
        $this->ensureOwner($request);
        $venue = $this->venue($request);
        $this->ensureAny($venue, ['shop']);
        $data = $request->validate($this->rules());
        $this->attempt(fn () => $shop->save(new Product(['venue_id' => $venue->id]), $data, $request->file('photo')));

        return back()->with('success', __('owner.flash.saved'));
    }

    /** POST with the photo (multipart), so not PUT. */
    public function update(Request $request, Product $product, Shop $shop)
    {
        $this->ensureOwner($request);
        abort_unless($product->venue_id === $this->venue($request)->id, 404);
        $data = $request->validate($this->rules());
        $this->attempt(fn () => $shop->save($product, $data, $request->file('photo')));

        return back()->with('success', __('owner.flash.saved'));
    }

    /** Sold for cash to someone without a card. */
    public function sell(Request $request, Product $product, Shop $shop)
    {
        $venue = $this->venue($request);
        $data = $request->validate(['qty' => 'required|integer|min:1|max:100']);
        $this->attempt(fn () => $shop->sell($venue, $product, (int) $data['qty'], 'cash', $request->user()));

        return back()->with('success', __('owner.club.sold'));
    }

    private function rules(): array
    {
        return [
            'name' => 'required|string|max:80', 'description' => 'nullable|string|max:300', 'price' => 'required|integer|min:0|max:10000000',
            'discount_pct' => 'nullable|integer|min:0|max:100', 'in_stock' => 'boolean', 'active' => 'boolean',
            'photo' => 'nullable|image|max:8192',
        ];
    }
}
