<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateWritingsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'CHAR', 'constraint' => 36],
            'user_id'         => ['type' => 'CHAR', 'constraint' => 36],
            'text'            => ['type' => 'TEXT'],
            'tone'            => ['type' => 'VARCHAR', 'constraint' => 16],
            'audience'        => ['type' => 'VARCHAR', 'constraint' => 16],
            'word_count'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'status'          => ['type' => 'VARCHAR', 'constraint' => 16],
            'failure_reason'  => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'idempotency_key' => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['user_id', 'idempotency_key']);
        $this->forge->addKey(['user_id', 'created_at']);
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('writings', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('writings', true);
    }
}
