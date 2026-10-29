<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** A page of a long list for a Vue page (the Pager component): the rows, this page, the number of pages and rows. */
class Paged
{
    public static function of(LengthAwarePaginator $p, callable $row): array
    {
        return ['data' => collect($p->items())->map($row)->values()->all(), 'page' => $p->currentPage(), 'pages' => $p->lastPage(), 'total' => $p->total()];
    }
}
