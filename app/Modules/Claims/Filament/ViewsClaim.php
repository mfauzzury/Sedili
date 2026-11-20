<?php

namespace App\Modules\Claims\Filament;

use Illuminate\Database\Eloquent\Model;

/**
 * For the view pages of a claim: loads what ClaimInfolist shows (lazy loading is forbidden).
 */
trait ViewsClaim
{
    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(ClaimInfolist::relations());
    }
}
