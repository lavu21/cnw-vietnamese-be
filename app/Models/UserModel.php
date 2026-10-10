<?php

namespace App\Models;

use CodeIgniter\Model;

class UserModel extends Model
{
    protected $table            = 'users';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $allowedFields    = ['id', 'username', 'password_hash'];
    protected $useTimestamps    = true;

    /** @return array<string, mixed>|null */
    public function findByUsername(string $username): ?array
    {
        return $this->where('username', $username)->first();
    }
}
