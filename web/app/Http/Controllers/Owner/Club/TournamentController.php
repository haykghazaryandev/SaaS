<?php

namespace App\Http\Controllers\Owner\Club;

use App\Domain\Club\Tournaments;
use App\Http\Controllers\Owner\OwnerController;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Support\LocalTime;
use App\Support\Phone;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Owner → Tournaments: open one (title, date, up to 3 prices), share its link, accept people, take fees, write results. */
class TournamentController extends OwnerController
{
    public function index(Request $request)
    {
        $venue = $this->venue($request);
        $this->ensureAny($venue, ['tournaments']);
        $list = Tournament::where('venue_id', $venue->id)->withCount('entries')->orderByDesc('starts_at')->get();
        $open = $request->query('t') ? $list->firstWhere('id', (int) $request->query('t')) : $list->first();

        return Inertia::render('owner/Tournaments', [
            'tournaments' => $list->map(fn (Tournament $t) => $this->present($t) + ['entries' => $t->entries_count])->values(),
            'current' => $open ? $this->present($open) + ['list' => $open->entries()->with('payment:id,amount,method')->orderBy('id')->get()
                ->map(fn (TournamentEntry $e) => [
                    'id' => $e->id, 'name' => $e->first_name.' '.$e->last_name, 'phone' => Phone::format($e->phone), 'tel' => $e->phone, 'level' => $e->level,
                    'option' => $open->options[$e->option]['label'] ?? '—', 'price' => (int) ($open->options[$e->option]['price'] ?? 0),
                    'status' => $e->status, 'place' => $e->place, 'paid' => $e->payment ? ['amount' => $e->payment->amount, 'method' => $e->payment->method] : null,
                ])->values()] : null,
            'sport' => ($venue->sports()[0] ?? \App\Enums\Sport::Football)->value,
        ]);
    }

    public function store(Request $request, Tournaments $tournaments)
    {
        $this->ensureOwner($request);
        $venue = $this->venue($request);
        $this->ensureAny($venue, ['tournaments']);
        $t = new Tournament(['venue_id' => $venue->id]);
        $t->setRelation('venue', $venue);
        $data = $this->data($request);
        $t = $this->attempt(fn () => $tournaments->save($t, $data));

        return redirect()->route('owner.tournaments', ['t' => $t->id])->with('success', __('owner.flash.saved'));
    }

    public function update(Request $request, Tournament $tournament, Tournaments $tournaments)
    {
        $this->ensureOwner($request);
        abort_unless($tournament->venue_id === $this->venue($request)->id, 404);
        $data = $this->data($request);
        $this->attempt(fn () => $tournaments->save($tournament, $data));

        return back()->with('success', __('owner.flash.saved'));
    }

    public function entry(Request $request, TournamentEntry $entry, Tournaments $tournaments)
    {
        abort_unless($entry->tournament->venue_id === $this->venue($request)->id, 404);
        $data = $request->validate(['status' => 'nullable|in:applied,accepted,declined', 'place' => 'nullable|integer|min:0|max:999', 'pay' => 'nullable|in:card,cash']);
        $this->attempt(function () use ($data, $entry, $tournaments, $request) {
            if (! empty($data['status'])) {
                $tournaments->decide($entry, $data['status']);
            }
            if (array_key_exists('place', $data)) {
                $tournaments->result($entry, $data['place']);
            }
            if (! empty($data['pay'])) {
                $tournaments->pay($entry, $data['pay'], $request->user());
            }
        });

        return back()->with('success', __('owner.flash.saved'));
    }

    private function data(Request $request): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:100', 'date' => 'required|date_format:Y-m-d', 'time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'description' => 'nullable|string|max:1000', 'status' => 'nullable|in:open,closed,done',
            'options' => 'required|array|min:1|max:3', 'options.*.label' => 'nullable|string|max:40', 'options.*.price' => 'nullable|integer|min:0|max:10000000',
        ]);
        [$h, $m] = array_map('intval', explode(':', $data['time']));
        $venue = $this->venue($request);

        return $data + [
            'starts_at' => LocalTime::at(LocalTime::day($data['date']), $h + $m / 60)->utc(),
            'sport' => ($venue->sports()[0] ?? \App\Enums\Sport::Football)->value,
        ];
    }

    private function present(Tournament $t): array
    {
        $at = $t->starts_at->setTimezone(LocalTime::tz());

        return ['id' => $t->id, 'title' => $t->title, 'date' => $at->toDateString(), 'time' => $at->format('H:i'), 'description' => $t->description,
            'options' => $t->options, 'status' => $t->status, 'link' => $t->link()];
    }
}
