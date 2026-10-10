<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use RuntimeException;

/** Creates the demo account used by the frontend login screen (development only). */
class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        if (ENVIRONMENT === 'production') {
            throw new RuntimeException('Refusing to seed the demo user in production. Use `php spark user:create`.');
        }

        if (service('authService')->findUserByUsername('demo') === null) {
            service('authService')->createUser('demo', 'demo123');
        }
    }
}
