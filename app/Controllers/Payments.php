<?php 
namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use App\Models\OrderModel;
use CodeIgniter\HTTP\CURLRequest;

class Payments extends Controller
{
    protected $orderModel;
    protected $apiBaseUrl = 'https://api.minepi.com/v2/me';
    protected $apiKey = '0yt8ackwv4axyafddxqu6izwtxqaaqgeq5rqymopwd1lkemv6jis8oynpeyondej';

    public function __construct()
    {
        $this->orderModel = new OrderModel();
        helper(['url', 'form']);
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
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

            $db = \Config\Database::connect();
            $db->transBegin();

            $this->orderModel->update($order['id'], ['txid' => $txid, 'paid' => true]);
            $this->sendApiRequest("/payments/{$paymentId}/complete", 'POST', ['txid' => $txid]);

            if ($db->transStatus() === false) {
                $db->transRollback();
                return $this->response->setStatusCode(ResponseInterface::HTTP_INTERNAL_SERVER_ERROR)->setJSON(['message' => 'Ödeme işlerken hata oluştu.']);
            } else {
                $db->transCommit();
            }

            return $this->response->setStatusCode(ResponseInterface::HTTP_OK)->setJSON(['message' => "Eksik ödeme işlendi: {$paymentId}"]);
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

            $db = \Config\Database::connect();
            $db->transBegin();

            $this->orderModel->insert($orderData);
            $this->sendApiRequest("/payments/{$paymentId}/approve", 'POST');

            if ($db->transStatus() === false) {
                $db->transRollback();
                return $this->response->setStatusCode(ResponseInterface::HTTP_INTERNAL_SERVER_ERROR)->setJSON(['message' => 'Ödeme onayı işlerken hata oluştu.']);
            } else {
                $db->transCommit();
            }

            return $this->response->setStatusCode(ResponseInterface::HTTP_OK)->setJSON(['message' => "Ödeme onaylandı: {$paymentId}"]);
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

            $db = \Config\Database::connect();
            $db->transBegin();

            $this->orderModel->update($order['id'], ['txid' => $txid, 'paid' => true]);
            $this->sendApiRequest("/payments/{$paymentId}/complete", 'POST', ['txid' => $txid]);

            if ($db->transStatus() === false) {
                $db->transRollback();
                return $this->response->setStatusCode(ResponseInterface::HTTP_INTERNAL_SERVER_ERROR)->setJSON(['message' => 'Ödeme tamamlarken hata oluştu.']);
            } else {
                $db->transCommit();
            }

            return $this->response->setStatusCode(ResponseInterface::HTTP_OK)->setJSON(['message' => "Ödeme tamamlandı: {$paymentId}"]);
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

            $db = \Config\Database::connect();
            $db->transBegin();

            $this->orderModel->update($order['id'], ['cancelled' => true]);

            if ($db->transStatus() === false) {
                $db->transRollback();
                return $this->response->setStatusCode(ResponseInterface::HTTP_INTERNAL_SERVER_ERROR)->setJSON(['message' => 'Ödeme iptal edilirken hata oluştu.']);
            } else {
                $db->transCommit();
            }

            return $this->response->setStatusCode(ResponseInterface::HTTP_OK)->setJSON(['message' => "Ödeme iptal edildi: {$paymentId}"]);
        } catch (\Exception $e) {
            return $this->response->setStatusCode(ResponseInterface::HTTP_INTERNAL_SERVER_ERROR)->setJSON(['message' => 'Beklenmeyen bir hata oluştu: ' . $e->getMessage()]);
        }
    }
}
