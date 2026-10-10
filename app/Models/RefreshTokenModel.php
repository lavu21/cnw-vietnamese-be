<?php

namespace App\Models;

use CodeIgniter\Model;

class RefreshTokenModel extends Model
{
    protected $table            = 'refresh_tokens';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $allowedFields    = ['id', 'user_id', 'family_id', 'token_hash', 'expires_at', 'used_at', 'revoked_at'];
    protected $useTimestamps    = true;
    protected $updatedField     = '';

    /** @return array<string, mixed>|null */
    public function findByHash(string $hash): ?array
    {
        return $this->where('token_hash', $hash)->first();
    }

    /** Atomically marks a token as used; false if it was already used or revoked. */
    public function consume(string $id): bool
    {
        $this->builder()
            ->where('id', $id)
            ->where('used_at', null)
            ->where('revoked_at', null)
            ->update(['used_at' => gmdate('Y-m-d H:i:s')]);

        return $this->db->affectedRows() > 0;
    }

    public function revokeFamily(string $familyId): void
    {
        $this->builder()
            ->where('family_id', $familyId)
            ->where('revoked_at', null)
            ->update(['revoked_at' => gmdate('Y-m-d H:i:s')]);
    }

    public function isFamilyActive(string $familyId): bool
    {
        return $this->builder()
            ->where('family_id', $familyId)
            ->where('revoked_at', null)
            ->where('expires_at >', gmdate('Y-m-d H:i:s'))
            ->countAllResults() > 0;
    }
}
