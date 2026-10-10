<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateWritingErrorsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'CHAR', 'constraint' => 36],
            'writing_id'  => ['type' => 'CHAR', 'constraint' => 36],
            'category'    => ['type' => 'VARCHAR', 'constraint' => 16],
            'status'      => ['type' => 'VARCHAR', 'constraint' => 16],
            'start_index' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'end_index'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'original'    => ['type' => 'TEXT'],
            'suggestion'  => ['type' => 'TEXT'],
            'explanation' => ['type' => 'TEXT'],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['writing_id', 'start_index']);
        $this->forge->addForeignKey('writing_id', 'writings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('writing_errors', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('writing_errors', true);
    }
}
