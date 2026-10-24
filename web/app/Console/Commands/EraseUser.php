<?php

namespace App\Console\Commands;

use App\Domain\Auth\AccountEraser;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Console\Command;

/** php artisan user:erase +37494369202 — delete someone completely, e.g. to test registration again. */
class EraseUser extends Command
{
    protected $signature = 'user:erase {phone : +374… or the local number} {--force : do not ask}';

    protected $description = 'Delete a user completely by phone (to register again with it)';

    public function handle(AccountEraser $eraser): int
    {
        $phone = Phone::normalize($this->argument('phone'));
        $user = $phone ? User::withTrashed()->where('phone', $phone)->first() : null;
        if (! $user) {
            $this->error('No user with this phone.');

            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm("Delete {$user->name} ({$user->phone}) completely?")) {
            return self::FAILURE;
        }
        $eraser->erase($user);
        $this->info('Deleted. The next login with this phone registers a new account.');

        return self::SUCCESS;
    }
}
