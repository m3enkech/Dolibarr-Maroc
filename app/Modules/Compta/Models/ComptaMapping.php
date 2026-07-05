<?php

namespace App\Modules\Compta\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['cle', 'compte_id'])]
class ComptaMapping extends Model
{
    use BelongsToTenant;

    protected $table = 'compta_mappings';

    public function compte(): BelongsTo
    {
        return $this->belongsTo(Compte::class);
    }
}
