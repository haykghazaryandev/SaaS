<?php

namespace App\Http\Controllers\Owner\Club;

use App\Domain\Club\Trainings;
use App\Http\Controllers\Owner\OwnerController;
use App\Models\Training;
use App\Models\TrainingVisit;
use App\Support\LocalTime;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Owner → Trainings: the schedule (days, time, coach, price) and who came, with the payments. */
class TrainingController extends OwnerController
{
    public function index(Request $request)
    {
        $venue = $this->venue($request);
        $this->ensureAny($venue, ['trainings']);
        $visits = TrainingVisit::whereHas('training', fn ($q) => $q->where('venue_id', $venue->id))
            ->with('user:id,name', 'training:id,title', 'payment:id,amount,method')->latest('id')->limit(60)->get();

        return Inertia::render('owner/Trainings', [
            'trainings' => Training::where('venue_id', $venue->id)->orderByDesc('active')->orderBy('starts_at')->get()->map(fn ($t) => Trainings::present($t))->values(),
            'visits' => $visits->map(fn (TrainingVisit $v) => ['id' => $v->id, 'who' => $v->user?->name, 'training' => $v->training?->title,
                'date' => $v->date->format('d.m'), 'amount' => $v->payment?->amount, 'method' => $v->payment?->method])->values(),
            'today' => LocalTime::now()->dayOfWeek,
        ]);
    }

    public function store(Request $request, Trainings $trainings)
    {
        $this->ensureOwner($request);
        $venue = $this->venue($request);
        $this->ensureAny($venue, ['trainings']);
        $training = new Training(['venue_id' => $venue->id]);
        $training->setRelation('venue', $venue);
        $this->attempt(fn () => $trainings->save($training, $request->validate($this->rules())));

        return back()->with('success', __('owner.flash.saved'));
    }

    public function update(Request $request, Training $training, Trainings $trainings)
    {
        $this->ensureOwner($request);
        abort_unless($training->venue_id === $this->venue($request)->id, 404);
        $this->attempt(fn () => $trainings->save($training, $request->validate($this->rules())));

        return back()->with('success', __('owner.flash.saved'));
    }

    private function rules(): array
    {
        return [
            'title' => 'required|string|max:80', 'coach' => 'nullable|string|max:80',
            'weekdays' => 'required|array|min:1', 'weekdays.*' => 'integer|between:0,6',
            'starts_at' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'], 'minutes' => 'required|integer|min:15|max:600',
            'price' => 'required|integer|min:0|max:1000000', 'capacity' => 'nullable|integer|min:1|max:500',
            'note' => 'nullable|string|max:300', 'active' => 'boolean',
        ];
    }
}
