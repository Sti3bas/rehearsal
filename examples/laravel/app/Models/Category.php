<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

final class Category extends Model
{
    use HasFactory, Searchable;

    public $timestamps = false;

    public function toSearchableArray(): array
    {
        return ['id' => (string) $this->id, 'name' => $this->name];
    }
}
