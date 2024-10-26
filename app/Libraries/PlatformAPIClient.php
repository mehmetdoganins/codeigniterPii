<?php 

namespace App\Libraries;

use CodeIgniter\HTTP\CURLRequest;

class PlatformAPIClient
{
    protected $client;

    public function __construct()
    {
        $this->client = \Config\Services::curlrequest([
            'baseURI' => getenv('PLATFORM_API_URL'),
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Key ' . getenv('PI_API_KEY')
            ]
        ]);
    }

    public function post($url, $data)
    {
        return $this->client->post($url, ['json' => $data]);
    }

    public function get($url)
    {
        return json_decode($this->client->get($url)->getBody(), true);
    }
}