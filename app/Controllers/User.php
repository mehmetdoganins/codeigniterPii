<?php // app/Controllers/User.php

namespace App\Controllers;

use CodeIgniter\Controller;

class User extends Controller
{
	public function index()
	{
		return $this->response->setJSON(['message' => 'User Endpoint']);
	}
}

