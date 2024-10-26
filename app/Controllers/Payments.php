<?php 
namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use App\Models\OrderModel;
use CodeIgniter\HTTP\CURLRequest;

class Payments extends Controller
{
    protected $orderModel;
    protected $apiBaseUrl;
    protected $apiKey;

    public function __construct()
    {
        $this->orderModel = new OrderModel();
        $this->apiBaseUrl = getenv('PI_API_BASE_URL');
        $this->apiKey = getenv('PI_API_KEY');
        helper(['url', 'form']);
    }

    private function sendApiRequest($endpoint, $method = 'GET', $data = [])
    {
        $client = \Config\Services::curlrequest([
            'baseURI' => $this->apiBaseUrl,
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Key ' . $this->apiKey
            ]
        ]);

        $response = $client->request($method, $endpoint, ['json' => $data]);
        return json_decode($response->getBody(), true);
    }

    private function handleDatabaseTransaction($callback)
    {
        $db = \Config\Database::connect();
        $db->transBegin();

        try {
            $result = $callback($db);
            if ($db->transStatus() === false) {
                $db->transRollback();
                return $this->response->setStatusCode(ResponseInterface::HTTP_INTERNAL_SERVER_ERROR)->setJSON(['message' => 'Veritabanı işlemi sırasında hata oluştu.']);
            } else {
                $db->transCommit();
                return $result;
            }
        } catch (\Exception $e) {
            $db->transRollback();
            return $this->response->setStatusCode(ResponseInterface::HTTP_INTERNAL_SERVER_ERROR)->setJSON(['message' => 'Beklenmeyen bir hata oluştu: ' . $e->getMessage()]);
        }
    }

    public function incomplete()
    {
        try {
            $payment = $this->request->getPost('payment');
            $paymentId = $payment['identifier'] ?? null;
            $txid = $payment['transaction']['txid'] ?? null;
            $txURL = $payment['transaction']['_link'] ?? null;

            if (!$paymentId || !$txid || !$txURL) {
                return $this->response->setStatusCode(ResponseInterface::HTTP_BAD_REQUEST)->setJSON(['message' => 'Eksik ödeme bilgileri']);
            }

            $order = $this->orderModel->where('pi_payment_id', $paymentId)->first();

            if (!$order) {
                return $this->response->setStatusCode(ResponseInterface::HTTP_NOT_FOUND)->setJSON(['message' => 'Sipariş bulunamadı']);
            }

            $horizonResponse = json_decode(file_get_contents($txURL), true);
            $paymentIdOnBlock = $horizonResponse['memo'] ?? null;

            if ($paymentIdOnBlock !== $order['pi_payment_id']) {
                return $this->response->setStatusCode(ResponseInterface::HTTP_BAD_REQUEST)->setJSON(['message' => 'Ödeme kimliği eşleşmiyor.']);
            }

            return $this->handleDatabaseTransaction(function($db) use ($order, $txid, $paymentId) {
                $this->orderModel->update($order['id'], ['txid' => $txid, 'paid' => true]);
                $this->sendApiRequest("/payments/{$paymentId}/complete", 'POST', ['txid' => $txid]);
                return $this->response->setStatusCode(ResponseInterface::HTTP_OK)->setJSON(['message' => "Eksik ödeme işlendi: {$paymentId}"]);
            });
        } catch (\Exception $e) {
            return $this->response->setStatusCode(ResponseInterface::HTTP_INTERNAL_SERVER_ERROR)->setJSON(['message' => 'Beklenmeyen bir hata oluştu: ' . $e->getMessage()]);
        }
    }

    public function approve()
    {
        try {
            $paymentId = $this->request->getPost('paymentId');

            if (!$paymentId) {
                return $this->response->setStatusCode(ResponseInterface::HTTP_BAD_REQUEST)->setJSON(['message' => 'Ödeme kimliği gerekli.']);
            }

            $currentPayment = $this->sendApiRequest("/payments/{$paymentId}");
            if (!$currentPayment) {
                return $this->response->setStatusCode(ResponseInterface::HTTP_NOT_FOUND)->setJSON(['message' => 'Ödeme bilgisi bulunamadı.']);
            }

            $orderData = [
                'pi_payment_id' => $paymentId,
                'product_id' => $currentPayment['data']['metadata']['productId'] ?? null,
                'txid' => null,
                'paid' => false,
                'cancelled' => false,
                'created_at' => date('Y-m-d H:i:s'),
            ];

            return $this->handleDatabaseTransaction(function($db) use ($orderData, $paymentId) {
                $this->orderModel->insert($orderData);
                $this->sendApiRequest("/payments/{$paymentId}/approve", 'POST');
                return $this->response->setStatusCode(ResponseInterface::HTTP_OK)->setJSON(['message' => "Ödeme onaylandı: {$paymentId}"]);
            });
        } catch (\Exception $e) {
            return $this->response->setStatusCode(ResponseInterface::HTTP_INTERNAL_SERVER_ERROR)->setJSON(['message' => 'Beklenmeyen bir hata oluştu: ' . $e->getMessage()]);
        }
    }

    public function complete()
    {
        try {
            $paymentId = $this->request->getPost('paymentId');
            $txid = $this->request->getPost('txid');

            if (!$paymentId || !$txid) {
                return $this->response->setStatusCode(ResponseInterface::HTTP_BAD_REQUEST)->setJSON(['message' => 'Eksik ödeme bilgileri.']);
            }

            $order = $this->orderModel->where('pi_payment_id', $paymentId)->first();
            if (!$order) {
                return $this->response->setStatusCode(ResponseInterface::HTTP_NOT_FOUND)->setJSON(['message' => 'Sipariş bulunamadı']);
            }

            return $this->handleDatabaseTransaction(function($db) use ($order, $txid, $paymentId) {
                $this->orderModel->update($order['id'], ['txid' => $txid, 'paid' => true]);
                $this->sendApiRequest("/payments/{$paymentId}/complete", 'POST', ['txid' => $txid]);
                return $this->response->setStatusCode(ResponseInterface::HTTP_OK)->setJSON(['message' => "Ödeme tamamlandı: {$paymentId}"]);
            });
        } catch (\Exception $e) {
            return $this->response->setStatusCode(ResponseInterface::HTTP_INTERNAL_SERVER_ERROR)->setJSON(['message' => 'Beklenmeyen bir hata oluştu: ' . $e->getMessage()]);
        }
    }

    public function cancelled_payment()
    {
        try {
            $paymentId = $this->request->getPost('paymentId');

            if (!$paymentId) {
                return $this->response->setStatusCode(ResponseInterface::HTTP_BAD_REQUEST)->setJSON(['message' => 'Ödeme kimliği gerekli.']);
            }

            $order = $this->orderModel->where('pi_payment_id', $paymentId)->first();
            if (!$order) {
                return $this->response->setStatusCode(ResponseInterface::HTTP_NOT_FOUND)->setJSON(['message' => 'Sipariş bulunamadı']);
            }

            return $this->handleDatabaseTransaction(function($db) use ($order, $paymentId) {
                $this->orderModel->update($order['id'], ['cancelled' => true]);
                return $this->response->setStatusCode(ResponseInterface::HTTP_OK)->setJSON(['message' => "Ödeme iptal edildi: {$paymentId}"]);
            });
        } catch (\Exception $e) {
            return $this->response->setStatusCode(ResponseInterface::HTTP_INTERNAL_SERVER_ERROR)->setJSON(['message' => 'Beklenmeyen bir hata oluştu: ' . $e->getMessage()]);
        }
    }
}