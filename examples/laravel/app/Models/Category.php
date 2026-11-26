<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

final class Category extends Model
{
    use Searchable;

    public function toSearchableArray(): array
    {
        return ['id' => (string) $this->id, 'name' => $this->name];
    }
}
