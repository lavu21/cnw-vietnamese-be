<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

class UserCreate extends BaseCommand
{
    protected $group       = 'App';
    protected $name        = 'user:create';
    protected $description = 'Creates a user account.';
    protected $usage       = 'user:create <username> [password]';
    protected $arguments   = [
        'username' => 'Login name (max 64 characters)',
        'password' => 'Password (prompted when omitted; 8-128 characters)',
    ];

    public function run(array $params)
    {
        $username = trim($params[0] ?? CLI::prompt('Username'));
        $password = $params[1] ?? CLI::prompt('Password');

        if ($username === '' || strlen($username) > 64 || strlen($password) < 8 || strlen($password) > 128) {
            CLI::error('Username must be 1-64 characters and password 8-128 characters.');

            return EXIT_USER_INPUT;
        }

        $auth = service('authService');

        if ($auth->findUserByUsername($username) !== null) {
            CLI::error("User \"{$username}\" already exists.");

            return EXIT_USER_INPUT;
        }

        try {
            $auth->createUser($username, $password);
        } catch (Throwable $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        CLI::write("User \"{$username}\" created.", 'green');
    }
}
