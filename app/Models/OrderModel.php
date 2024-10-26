<?php namespace App\Models;

use CodeIgniter\Model;

class OrderModel extends Model
{
    protected $table = 'orders';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'pi_payment_id', 'product_id', 'txid', 'paid', 'cancelled', 'created_at'
    ];
}

