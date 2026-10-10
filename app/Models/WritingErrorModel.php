<?php

namespace App\Models;

use CodeIgniter\Model;

class WritingErrorModel extends Model
{
    protected $table            = 'writing_errors';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $allowedFields    = [
        'id', 'writing_id', 'category', 'status', 'start_index', 'end_index', 'original', 'suggestion', 'explanation',
    ];
    protected $useTimestamps = true;

    /** @return list<array<string, mixed>> */
    public function forWriting(string $writingId): array
    {
        return $this->where('writing_id', $writingId)->orderBy('start_index', 'ASC')->findAll();
    }

    /**
     * Pending/total counts per writing, for the history list.
     *
     * @param list<string> $writingIds
     *
     * @return array<string, array{total: int, pending: int}>
     */
    public function summariesFor(array $writingIds): array
    {
        if ($writingIds === []) {
            return [];
        }

        $rows = $this->builder()
            ->select("writing_id, COUNT(*) AS total, SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending", false)
            ->whereIn('writing_id', $writingIds)
            ->groupBy('writing_id')
            ->get()
            ->getResultArray();

        $summaries = [];

        foreach ($rows as $row) {
            $summaries[$row['writing_id']] = ['total' => (int) $row['total'], 'pending' => (int) $row['pending']];
        }

        return $summaries;
    }
}
