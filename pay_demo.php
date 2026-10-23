<?php
// pay_demo.php - Demo M-Pesa Payment Trigger Page
require_once __DIR__ . '/includes/db.php';

$guardian_id = intval($_GET['guardian_id'] ?? 0);
$amount      = floatval($_GET['amount'] ?? 0);
$phone       = trim($_GET['phone'] ?? '');

$pdo = get_db();
$stmt = $pdo->prepare("SELECT full_name FROM guardians WHERE id = ?");
$stmt->execute([$guardian_id]);
$guardian = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$guardian) {
    die("Invalid session or registration reference.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>M-Pesa Checkout | MIST</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        mistnavy: '#262262',
                        mistgold: '#FFC000',
                        mistgreen: '#6dad13',
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-slate-100 min-h-screen flex items-center justify-center p-4">

    <div class="max-w-md w-full bg-white rounded-2xl shadow-2xl overflow-hidden border border-slate-200">
        
        <!-- M-Pesa Express Branding Banner -->
        <div class="bg-mistgreen p-6 text-white text-center relative">
            <div class="w-16 h-16 bg-white rounded-full flex items-center justify-center mx-auto mb-3 shadow">
                <i class="fas fa-mobile-screen-button text-mistgreen text-3xl"></i>
            </div>
            <h2 class="text-xl font-bold">M-Pesa Express Payment</h2>
            <p class="text-xs text-emerald-100 mt-1">Musketeers Institute of Science and Technology</p>
        </div>

        <div class="p-6 space-y-6">
            
            <div class="bg-slate-50 rounded-xl p-4 border border-slate-200 text-sm space-y-2">
                <div class="flex justify-between text-slate-600">
                    <span>Payer Name:</span>
                    <span class="font-bold text-slate-800"><?= htmlspecialchars($guardian['full_name']) ?></span>
                </div>
                <div class="flex justify-between text-slate-600">
                    <span>Phone Number:</span>
                    <span class="font-bold text-slate-800"><?= htmlspecialchars($phone) ?></span>
                </div>
                <div class="border-t border-slate-200 pt-2 flex justify-between items-center text-lg">
                    <span class="font-bold text-mistnavy">Total Payable:</span>
                    <span class="font-extrabold text-mistgreen">KSh <?= number_format($amount, 2) ?></span>
                </div>
            </div>

            <!-- Simulation Controls -->
            <form id="stk-form" class="space-y-4">
                <input type="hidden" name="guardian_id" value="<?= $guardian_id ?>">
                <input type="hidden" name="amount" value="<?= $amount ?>">
                <input type="hidden" name="phone" value="<?= htmlspecialchars($phone) ?>">

                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1">M-Pesa Phone Number for Prompt</label>
                    <input type="text" name="mpesa_phone" value="<?= htmlspecialchars($phone) ?>" class="w-full px-4 py-2 border rounded-lg text-sm font-semibold text-slate-700">
                </div>

                <button type="submit" id="pay-btn" class="w-full bg-mistgreen hover:bg-emerald-700 text-white font-bold py-3.5 rounded-xl shadow-lg transition flex items-center justify-center gap-2">
                    <i class="fas fa-paper-plane"></i> Send M-Pesa STK Push Prompt
                </button>
            </form>

            <div id="status-box" class="hidden rounded-xl p-4 text-xs"></div>
        </div>

        <div class="bg-slate-50 px-6 py-3 border-t text-center text-xs text-slate-400">
            MIST Secure Payment Gateway • Demo Environment
        </div>
    </div>

    <script>
        document.getElementById('stk-form').addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = document.getElementById('pay-btn');
            const statusBox = document.getElementById('status-box');

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Triggering Phone Prompt...';

            statusBox.className = 'rounded-xl p-4 text-xs bg-amber-50 text-amber-800 border border-amber-200 flex items-center gap-2';
            statusBox.innerHTML = '<i class="fas fa-mobile-retro fa-bounce text-base"></i> Please check your mobile phone for M-Pesa PIN prompt...';
            statusBox.classList.remove('hidden');

            const formData = new FormData(this);

            fetch('api/mpesa_stk_demo.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'SUCCESS') {
                    statusBox.className = 'rounded-xl p-4 text-xs bg-emerald-50 text-emerald-800 border border-emerald-200 space-y-1';
                    statusBox.innerHTML = `
                        <p class="font-bold flex items-center gap-2"><i class="fas fa-circle-check text-mistgreen"></i> Payment Received Successfully!</p>
                        <p>Receipt Code: <strong>${data.mpesa_code}</strong></p>
                        <p>Redirecting to completion receipt...</p>
                    `;
                    setTimeout(() => {
                        window.location.href = 'receipt.php?code=' + data.mpesa_code;
                    }, 2500);
                } else {
                    statusBox.className = 'rounded-xl p-4 text-xs bg-rose-50 text-rose-800 border border-rose-200';
                    statusBox.innerHTML = '<i class="fas fa-circle-xmark"></i> ' + data.message;
                    btn.disabled = false;
                    btn.innerHTML = 'Retry M-Pesa Prompt';
                }
            })
            .catch(err => {
                statusBox.className = 'rounded-xl p-4 text-xs bg-rose-50 text-rose-800 border border-rose-200';
                statusBox.innerHTML = 'Transaction failed. Please try again.';
                btn.disabled = false;
                btn.innerHTML = 'Retry M-Pesa Prompt';
            });
        });
    </script>
</body>
</html>