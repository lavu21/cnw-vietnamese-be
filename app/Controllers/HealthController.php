<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

class HealthController extends BaseController
{
    public function index(): ResponseInterface
    {
        try {
            db_connect()->query('SELECT 1');
        } catch (Throwable) {
            return $this->response->setStatusCode(503)->setJSON(['status' => 'unavailable']);
        }

        return $this->response->setJSON(['status' => 'ok']);
    }
}
