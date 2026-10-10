<?php

namespace App\Models;

use CodeIgniter\Model;

class WritingModel extends Model
{
    protected $table            = 'writings';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $allowedFields    = [
        'id', 'user_id', 'text', 'tone', 'audience', 'word_count', 'status', 'failure_reason', 'idempotency_key',
    ];
    protected $useTimestamps = true;

    /** Always scoped to the owner so other users' rows are indistinguishable from missing ones. */
    public function findOwned(string $id, string $userId): ?array
    {
        return $this->where(['id' => $id, 'user_id' => $userId])->first();
    }

    public function findByIdempotencyKey(string $userId, string $key): ?array
    {
        return $this->where(['user_id' => $userId, 'idempotency_key' => $key])->first();
    }

    /**
     * @param array{q?: string, tone?: string, audience?: string, from?: string, to?: string} $filters
     */
    public function applyFilters(string $userId, array $filters): static
    {
        $this->where('user_id', $userId);

        if (($filters['q'] ?? '') !== '') {
            $this->like('text', $filters['q']);
        }

        foreach (['tone', 'audience'] as $field) {
            if (($filters[$field] ?? '') !== '') {
                $this->where($field, $filters[$field]);
            }
        }

        if (($filters['from'] ?? '') !== '') {
            $this->where('created_at >=', $filters['from']);
        }

        if (($filters['to'] ?? '') !== '') {
            $this->where('created_at <=', $filters['to']);
        }

        return $this;
    }
}
