<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_roles([ROLE_SUPER_ADMIN, ROLE_ADMIN]);

$paymentConfig = require __DIR__ . '/../../config/payment.php';

function resolve_accessible_student_ids_checkout(PDO $pdo, array $user): ?array
{
    if (in_array($user['role'], [ROLE_SUPER_ADMIN, ROLE_ADMIN], true)) {
        return null;
    }

    if ($user['role'] === ROLE_STUDENT) {
        $statement = $pdo->prepare('SELECT id FROM students WHERE user_id = :user_id');
        $statement->execute(['user_id' => $user['id']]);
        return array_map(static fn(array $row): int => (int)$row['id'], $statement->fetchAll());
    }

    if ($user['role'] === ROLE_PARENT) {
        $statement = $pdo->prepare(
            'SELECT ps.student_id
             FROM parents p
             JOIN parent_student ps ON ps.parent_id = p.id
             WHERE p.user_id = :user_id'
        );
        $statement->execute(['user_id' => $user['id']]);
        return array_map(static fn(array $row): int => (int)$row['student_id'], $statement->fetchAll());
    }

    return [];
}

function is_fee_accessible(array $feeRow, ?array $studentIds): bool
{
    if ($studentIds === null) {
        return true;
    }
    return in_array((int)$feeRow['student_id'], $studentIds, true);
}

function calculate_new_fee_status(float $payableAmount, float $paidAmount): string
{
    if ($payableAmount <= 0 || $paidAmount >= $payableAmount) {
        return 'paid';
    }
    if ($paidAmount > 0 && $paidAmount < $payableAmount) {
        return 'partial';
    }
    return 'pending';
}

function create_razorpay_order(array $paymentConfig, int $amountPaise, string $receipt): array
{
    $payload = json_encode([
        'amount' => $amountPaise,
        'currency' => $paymentConfig['currency'],
        'receipt' => $receipt,
        'payment_capture' => 1,
    ]);

    if ($payload === false) {
        throw new RuntimeException('Failed to encode Razorpay payload.');
    }

    $curl = curl_init('https://api.razorpay.com/v1/orders');
    if ($curl === false) {
        throw new RuntimeException('Unable to initialize Razorpay client.');
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_USERPWD => $paymentConfig['public_key'] . ':' . $paymentConfig['secret_key'],
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($curl);
    if ($response === false) {
        $error = curl_error($curl);
        throw new RuntimeException('Razorpay request failed: ' . $error);
    }

    $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid Razorpay response.');
    }

    if ($httpCode < 200 || $httpCode >= 300 || empty($decoded['id'])) {
        $errorDescription = $decoded['error']['description'] ?? 'Unknown Razorpay error.';
        throw new RuntimeException('Razorpay order creation failed: ' . $errorDescription);
    }

    return $decoded;
}

function verify_razorpay_signature(string $orderId, string $paymentId, string $signature, string $secret): bool
{
    $payload = $orderId . '|' . $paymentId;
    $expected = hash_hmac('sha256', $payload, $secret);
    return hash_equals($expected, $signature);
}

$errors = [];
$success = [];
$user = current_user();
$accessibleStudentIds = resolve_accessible_student_ids_checkout($pdo, $user);

$isRazorpay = strtolower((string)($paymentConfig['provider'] ?? '')) === 'razorpay';
$hasRazorpayKeys = !empty($paymentConfig['public_key']) && !empty($paymentConfig['secret_key']);
$useRazorpay = $isRazorpay && $hasRazorpayKeys;

$studentFeeId = (int)($_GET['student_fee_id'] ?? $_POST['student_fee_id'] ?? 0);
$requestedAmount = (float)($_GET['amount'] ?? $_POST['amount'] ?? 0);

if (!isset($_SESSION['razorpay_orders']) || !is_array($_SESSION['razorpay_orders'])) {
    $_SESSION['razorpay_orders'] = [];
}

if ($studentFeeId <= 0 || $requestedAmount <= 0) {
    $errors[] = 'Invalid payment request.';
}

$fee = null;
if (empty($errors)) {
    $feeStmt = $pdo->prepare(
        'SELECT sf.id, sf.student_id, sf.period_label, sf.payable_amount, sf.paid_amount, sf.status,
                fs.fee_title, fs.billing_cycle,
                su.full_name AS student_name
         FROM student_fees sf
         JOIN fee_structures fs ON fs.id = sf.fee_structure_id
         JOIN students s ON s.id = sf.student_id
         LEFT JOIN users su ON su.id = s.user_id
         WHERE sf.id = :id
         LIMIT 1'
    );
    $feeStmt->execute(['id' => $studentFeeId]);
    $fee = $feeStmt->fetch();

    if (!$fee) {
        $errors[] = 'Fee record not found.';
    } elseif (!is_fee_accessible($fee, $accessibleStudentIds)) {
        $errors[] = 'Access denied for this fee record.';
    }
}

$razorpayOrder = null;

if (empty($errors) && $useRazorpay && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'razorpay_callback') {
    $paymentId = trim($_POST['razorpay_payment_id'] ?? '');
    $orderId = trim($_POST['razorpay_order_id'] ?? '');
    $signature = trim($_POST['razorpay_signature'] ?? '');

    if ($paymentId === '' || $orderId === '' || $signature === '') {
        $errors[] = 'Invalid Razorpay callback payload.';
    } elseif (!verify_razorpay_signature($orderId, $paymentId, $signature, (string)$paymentConfig['secret_key'])) {
        $errors[] = 'Razorpay signature verification failed.';
    } elseif (!isset($_SESSION['razorpay_orders'][$orderId])) {
        $errors[] = 'Razorpay order session expired. Please retry payment.';
    } else {
        $orderSession = $_SESSION['razorpay_orders'][$orderId];
        if ((int)$orderSession['user_id'] !== (int)$user['id']) {
            $errors[] = 'Payment session does not belong to current user.';
        } else {
            try {
                $pdo->beginTransaction();

                $feeForUpdateStmt = $pdo->prepare(
                    'SELECT id, student_id, payable_amount, paid_amount
                     FROM student_fees
                     WHERE id = :id
                     FOR UPDATE'
                );
                $feeForUpdateStmt->execute(['id' => (int)$orderSession['student_fee_id']]);
                $feeForUpdate = $feeForUpdateStmt->fetch();

                if (!$feeForUpdate) {
                    throw new RuntimeException('Fee record not found during callback.');
                }

                if (!is_fee_accessible($feeForUpdate, $accessibleStudentIds)) {
                    throw new RuntimeException('Access denied for this fee record.');
                }

                $amount = (float)$orderSession['amount'];
                $outstanding = max(0, (float)$feeForUpdate['payable_amount'] - (float)$feeForUpdate['paid_amount']);
                if ($amount <= 0 || $amount > $outstanding) {
                    throw new RuntimeException('Invalid callback amount for current outstanding balance.');
                }

                $existsPaymentStmt = $pdo->prepare(
                    'SELECT id FROM payments WHERE transaction_ref = :transaction_ref LIMIT 1'
                );
                $existsPaymentStmt->execute(['transaction_ref' => $paymentId]);

                if (!$existsPaymentStmt->fetch()) {
                    $insertPayment = $pdo->prepare(
                        'INSERT INTO payments (student_fee_id, amount, payment_date, method, transaction_ref, status, received_by)
                         VALUES (:student_fee_id, :amount, NOW(), :method, :transaction_ref, :status, :received_by)'
                    );
                    $insertPayment->execute([
                        'student_fee_id' => (int)$feeForUpdate['id'],
                        'amount' => $amount,
                        'method' => 'online_gateway',
                        'transaction_ref' => $paymentId,
                        'status' => 'success',
                        'received_by' => null,
                    ]);
                }

                $newPaidAmount = (float)$feeForUpdate['paid_amount'] + $amount;
                $payableAmount = (float)$feeForUpdate['payable_amount'];
                if ($newPaidAmount > $payableAmount) {
                    $newPaidAmount = $payableAmount;
                }

                $updateFee = $pdo->prepare(
                    'UPDATE student_fees
                     SET paid_amount = :paid_amount,
                         status = :status
                     WHERE id = :id'
                );
                $updateFee->execute([
                    'paid_amount' => $newPaidAmount,
                    'status' => calculate_new_fee_status($payableAmount, $newPaidAmount),
                    'id' => (int)$feeForUpdate['id'],
                ]);

                $pdo->commit();

                unset($_SESSION['razorpay_orders'][$orderId]);
                header('Location: /itierp/modules/fees/student_fees.php?payment=success');
                exit;
            } catch (Throwable $throwable) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Razorpay callback failed: ' . $throwable->getMessage();
            }
        }
    }
}

if (empty($errors) && $useRazorpay && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_razorpay_order') {
    $outstanding = max(0, (float)$fee['payable_amount'] - (float)$fee['paid_amount']);
    if ($requestedAmount > $outstanding) {
        $errors[] = 'Payment amount exceeds outstanding balance.';
    } else {
        try {
            $amountPaise = (int)round($requestedAmount * 100);
            $receipt = 'fee_' . $studentFeeId . '_' . time();
            $razorpayOrder = create_razorpay_order($paymentConfig, $amountPaise, $receipt);

            $_SESSION['razorpay_orders'][$razorpayOrder['id']] = [
                'student_fee_id' => $studentFeeId,
                'amount' => $requestedAmount,
                'user_id' => (int)$user['id'],
                'created_at' => time(),
            ];
        } catch (Throwable $throwable) {
            $errors[] = $throwable->getMessage();
        }
    }
}

if (empty($errors) && !$useRazorpay && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_payment') {
    $outstanding = max(0, (float)$fee['payable_amount'] - (float)$fee['paid_amount']);
    if ($requestedAmount > $outstanding) {
        $errors[] = 'Payment amount exceeds outstanding balance.';
    } else {
        try {
            $pdo->beginTransaction();

            $transactionRef = 'PG' . date('YmdHis') . random_int(10000, 99999);
            $insertPayment = $pdo->prepare(
                'INSERT INTO payments (student_fee_id, amount, payment_date, method, transaction_ref, status, received_by)
                 VALUES (:student_fee_id, :amount, NOW(), :method, :transaction_ref, :status, :received_by)'
            );
            $insertPayment->execute([
                'student_fee_id' => $studentFeeId,
                'amount' => $requestedAmount,
                'method' => 'online_gateway',
                'transaction_ref' => $transactionRef,
                'status' => 'success',
                'received_by' => null,
            ]);

            $newPaidAmount = (float)$fee['paid_amount'] + $requestedAmount;
            $payableAmount = (float)$fee['payable_amount'];
            if ($newPaidAmount > $payableAmount) {
                $newPaidAmount = $payableAmount;
            }

            $newStatus = 'pending';
            if ($newPaidAmount >= $payableAmount && $payableAmount > 0) {
                $newStatus = 'paid';
            } elseif ($newPaidAmount > 0 && $newPaidAmount < $payableAmount) {
                $newStatus = 'partial';
            } elseif ($payableAmount <= 0) {
                $newStatus = 'paid';
            }

            $updateFee = $pdo->prepare(
                'UPDATE student_fees
                 SET paid_amount = :paid_amount,
                     status = :status
                 WHERE id = :id'
            );
            $updateFee->execute([
                'paid_amount' => $newPaidAmount,
                'status' => $newStatus,
                'id' => $studentFeeId,
            ]);

            $pdo->commit();
            header('Location: /itierp/modules/fees/student_fees.php?payment=success');
            exit;
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Online payment failed: ' . $throwable->getMessage();
        }
    }
}

$pageTitle = 'Online Fee Checkout';
require __DIR__ . '/../../includes/header.php';
?>
<section class="card">
    <h2>Online Fee Checkout</h2>

    <?php if (!empty($errors)): ?>
        <div class="error">
            <?php foreach ($errors as $error): ?>
                <p><?= htmlspecialchars($error) ?></p>
            <?php endforeach; ?>
        </div>
        <p><a href="/itierp/modules/fees/student_fees.php">Back to Fees</a></p>
    <?php else: ?>
        <?php $outstanding = max(0, (float)$fee['payable_amount'] - (float)$fee['paid_amount']); ?>
        <div class="table-wrap">
            <table>
                <tbody>
                    <tr><th>Student</th><td><?= htmlspecialchars((string)$fee['student_name']) ?></td></tr>
                    <tr><th>Fee</th><td><?= htmlspecialchars((string)$fee['fee_title']) ?> (<?= htmlspecialchars((string)$fee['billing_cycle']) ?>)</td></tr>
                    <tr><th>Period</th><td><?= htmlspecialchars((string)$fee['period_label']) ?></td></tr>
                    <tr><th>Outstanding</th><td><?= number_format($outstanding, 2) ?> <?= htmlspecialchars($paymentConfig['currency']) ?></td></tr>
                    <tr><th>Paying Now</th><td><?= number_format($requestedAmount, 2) ?> <?= htmlspecialchars($paymentConfig['currency']) ?></td></tr>
                    <tr><th>Gateway</th><td><?= htmlspecialchars(strtoupper((string)$paymentConfig['provider'])) ?><?= $paymentConfig['sandbox'] ? ' (Sandbox)' : '' ?></td></tr>
                </tbody>
            </table>
        </div>

        <?php if ($useRazorpay): ?>
            <?php if ($razorpayOrder): ?>
                <button type="button" id="payWithRazorpayBtn">Continue to Razorpay</button>
                <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
                <script>
                    (function () {
                        const options = {
                            key: <?= json_encode((string)$paymentConfig['public_key']) ?>,
                            amount: <?= json_encode((int)round($requestedAmount * 100)) ?>,
                            currency: <?= json_encode((string)$paymentConfig['currency']) ?>,
                            name: <?= json_encode((string)$paymentConfig['merchant_name']) ?>,
                            description: <?= json_encode((string)($fee['fee_title'] . ' - ' . $fee['period_label'])) ?>,
                            order_id: <?= json_encode((string)$razorpayOrder['id']) ?>,
                            callback_url: '/itierp/modules/fees/checkout.php?action=razorpay_callback&student_fee_id=<?= (int)$studentFeeId ?>&amount=<?= urlencode((string)$requestedAmount) ?>',
                            prefill: {
                                name: <?= json_encode((string)$user['name']) ?>,
                                email: <?= json_encode((string)$user['email']) ?>
                            },
                            theme: {
                                color: '#2563eb'
                            }
                        };
                        const rzp = new Razorpay(options);
                        document.getElementById('payWithRazorpayBtn').addEventListener('click', function () {
                            rzp.open();
                        });
                        rzp.open();
                    })();
                </script>
            <?php else: ?>
                <form method="post" class="form-grid form-submit-spacer">
                    <input type="hidden" name="action" value="create_razorpay_order">
                    <input type="hidden" name="student_fee_id" value="<?= (int)$studentFeeId ?>">
                    <input type="hidden" name="amount" value="<?= htmlspecialchars((string)$requestedAmount) ?>">
                    <button type="submit">Pay with Razorpay</button>
                </form>
            <?php endif; ?>
        <?php else: ?>
            <form method="post" class="form-grid form-submit-spacer">
                <input type="hidden" name="action" value="confirm_payment">
                <input type="hidden" name="student_fee_id" value="<?= (int)$studentFeeId ?>">
                <input type="hidden" name="amount" value="<?= htmlspecialchars((string)$requestedAmount) ?>">
                <button type="submit">Confirm and Pay</button>
            </form>
        <?php endif; ?>
        <p class="table-wrap-offset"><a href="/itierp/modules/fees/student_fees.php">Cancel</a></p>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
