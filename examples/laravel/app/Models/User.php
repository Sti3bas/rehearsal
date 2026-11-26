<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Scout\Searchable;

final class User extends Authenticatable
{
    use HasFactory, Searchable, SoftDeletes;

    protected $fillable = ['name', 'email'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }

    public function getConnectionName()
    {
        return parent::getConnectionName() ?? 'members';
    }

    public function toSearchableArray(): array
    {
        return ['id' => (string) $this->id, 'name' => $this->name];
    }

    public function typesenseCollectionSchema(): array
    {
        return ['fields' => [['name' => 'name', 'type' => 'string']]];
    }
}
