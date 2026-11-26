<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

final class User extends Authenticatable
{
    use HasFactory;

    protected $fillable = ['name', 'email'];

    protected $hidden = ['password'];

    public function getConnectionName()
    {
        return parent::getConnectionName() ?? 'members';
    }
}
