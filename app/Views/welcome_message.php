<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pi Network Uygulaması</title>
    <script src="https://sdk.minepi.com/pi-sdk.js"></script>
    <style>
        .hidden {
            display: none;
        }
        .log-message {
            font-size: 0.9em;
            color: #333;
        }
    </style>
</head>
<body>
    <h1>Pi Network Uygulaması</h1>
    <div id="app">
        <button id="authenticateBtn">Kimlik Doğrula</button>
        <div id="userInfo" class="hidden">
            <h2>Kullanıcı Bilgileri</h2>
            <p id="username"></p>
            <button id="paymentBtn">Ödeme Yap</button>
            <p id="loadingMessage" class="hidden">Ödeme hazırlanıyor, lütfen bekleyin...</p>
        </div>
        <div id="logContainer" class="log-message"></div>
    </div>
    <script>
        document.addEventListener("DOMContentLoaded", () => {
            const logContainer = document.getElementById('logContainer');

            function logMessage(message) {
                console.log(message);
                const logEntry = document.createElement('p');
                logEntry.textContent = message;
                logContainer.appendChild(logEntry);
            }

            if (typeof Pi === "undefined") {
                logMessage("Pi SDK yüklenemedi. Lütfen SDK'nin yüklendiğinden emin olun.");
                alert("Pi SDK yüklenemedi. Lütfen sayfayı yenileyin.");
                return;
            }

            Pi.init({ version: "2.0", sandbox: false });
            logMessage("Pi SDK başlatıldı.");

            const authenticateBtn = document.getElementById('authenticateBtn');
            const userInfo = document.getElementById('userInfo');
            const usernameEl = document.getElementById('username');
            const paymentBtn = document.getElementById('paymentBtn');
            const loadingMessage = document.getElementById('loadingMessage');

            authenticateBtn.addEventListener('click', async () => {
                try {
                    logMessage("Kimlik doğrulama başlatıldı.");
                    const scopes = ['username', 'payments'];
                    const auth = await Pi.authenticate(scopes, (payment) => {
                        logMessage('Tamamlanmamış ödeme bulundu: ' + JSON.stringify(payment));
                    });

                    usernameEl.textContent = `Merhaba, ${auth.user.username}!`;
                    userInfo.classList.remove('hidden');
                    authenticateBtn.classList.add('hidden');
                    logMessage("Kimlik doğrulama başarılı: " + auth.user.username);
                } catch (error) {
                    logMessage('Kimlik doğrulama hatası: ' + error.message);
                    alert('Kimlik doğrulama başarısız oldu. Lütfen tekrar deneyin.');
                }
            });

            paymentBtn.addEventListener('click', async () => {
                try {
                    loadingMessage.classList.remove('hidden');
                    paymentBtn.disabled = true;
                    logMessage("Ödeme başlatıldı.");

                    const paymentData = {
                        amount: 1,
                        memo: "Test Ödemesi",
                        metadata: { orderId: Date.now().toString() }
                    };

                    const payment = await Pi.createPayment({
                        amount: paymentData.amount,
                        memo: paymentData.memo,
                        metadata: paymentData.metadata
                    }, {
                        onReadyForServerApproval: async (paymentId) => {
                            logMessage('Ödeme onay bekliyor: ' + paymentId);
                            try {
                                const response = await fetchWithTimeout(`https://pidirect.net.tr/payments/approve/${paymentId}`, {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json'
                                    },
                                    body: JSON.stringify({ paymentId })
                                }, 5000);
                                if (response.ok) {
                                    logMessage('Ödeme sunucu onayı tamamlandı: ' + paymentId);
                                } else {
                                    logMessage('Sunucu onayı hatası: ' + response.statusText);
                                }
                            } catch (error) {
                                logMessage('Sunucu onayı hatası: ' + error.message);
                            }
                        },
                        onReadyForServerCompletion: async (paymentId, txid) => {
                            logMessage('Ödeme tamamlanmak üzere: ' + paymentId + ', txid: ' + txid);
                            try {
                                const response = await fetchWithTimeout(`https://pidirect.net.tr/payments/complete/${paymentId}`, {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json'
                                    },
                                    body: JSON.stringify({ paymentId, txid })
                                }, 5000);
                                if (response.ok) {
                                    alert('Ödeme başarıyla tamamlandı!');
                                    logMessage('Ödeme başarıyla tamamlandı: ' + paymentId);
                                } else {
                                    logMessage('Sunucu tamamlanma hatası: ' + response.statusText);
                                }
                            } catch (error) {
                                logMessage('Sunucu tamamlanma hatası: ' + error.message);
                            } finally {
                                loadingMessage.classList.add('hidden');
                                paymentBtn.disabled = false;
                            }
                        },
                        onCancel: async (paymentId) => {
                            logMessage('Ödeme iptal edildi: ' + paymentId);
                            try {
                                const response = await fetchWithTimeout(`https://pidirect.net.tr/payments/cancel/${paymentId}`, {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json'
                                    },
                                    body: JSON.stringify({ paymentId })
                                }, 5000);
                                if (response.ok) {
                                    alert('Ödeme iptal edildi.');
                                } else {
                                    logMessage('Sunucu iptal hatası: ' + response.statusText);
                                }
                            } catch (error) {
                                logMessage('Sunucu iptal hatası: ' + error.message);
                            } finally {
                                loadingMessage.classList.add('hidden');
                                paymentBtn.disabled = false;
                            }
                        },
                        onError: (error, payment) => {
                            logMessage('Ödeme hatası: ' + error.message);
                            alert('Ödeme sırasında bir hata oluştu. Lütfen tekrar deneyin.');
                            loadingMessage.classList.add('hidden');
                            paymentBtn.disabled = false;
                        }
                    });

                    if (payment) {
                        logMessage('Ödeme oluşturuldu: ' + JSON.stringify(payment));
                    }
                } catch (error) {
                    logMessage('Ödeme hatası: ' + error.message);
                    alert('Ödeme sırasında bir hata oluştu. Lütfen tekrar deneyin.');
                    loadingMessage.classList.add('hidden');
                    paymentBtn.disabled = false;
                }
            });

            // Zaman aşımı için bir yardımcı fonksiyon
            const timeout = (ms) => new Promise(resolve => setTimeout(resolve, ms));
            const fetchWithTimeout = async (resource, options = {}, timeoutMs = 5000) => {
                const controller = new AbortController();
                const id = setTimeout(() => controller.abort(), timeoutMs);
                try {
                    const response = await fetch(resource, {
                        ...options,
                        signal: controller.signal
                    });
                    clearTimeout(id);
                    return response;
                } catch (error) {
                    clearTimeout(id);
                    throw error;
                }
            };
        });
    </script>
</body>
</html>
