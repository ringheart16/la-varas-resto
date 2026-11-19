<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require __DIR__ . '/config.php';
require __DIR__ . '/bootstrap.php';
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function respond($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}
function body() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}
function orderCode() {
    return 'ORD-' . date('YmdHis') . '-' . random_int(100,999);
}

function requireAjax() {
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') respond(['success'=>false,'message'=>'Invalid request.'],403);
}
function requireCustomer(PDO $pdo) {
    $id = (int)($_SESSION['customer_id'] ?? 0);
    if ($id > 0) {
        $st = $pdo->prepare('SELECT id,username,name,phone FROM customers WHERE id=?');
        $st->execute([$id]);
        $c = $st->fetch();
        if ($c) return $c;
    }
    respond(['success'=>false,'message'=>'Please sign in first.'],401);
}
function requireCashier() {
    if ((int)($_SESSION['cashier_id'] ?? 0) <= 0) respond(['success'=>false,'message'=>'Cashier session expired. Please sign out and sign in again.'],401);
    return (int)$_SESSION['cashier_id'];
}
function ordersWithItems(PDO $pdo, $where, array $params, $limit = 100) {
    $limit = (int)$limit;
    $st = $pdo->prepare("SELECT id,order_code,order_type,customer_name,subtotal,vat,total,payment_method,payment_reference,status,created_at FROM orders WHERE $where ORDER BY id DESC LIMIT $limit");
    $st->execute($params);
    $orders = $st->fetchAll();
    if (!$orders) return [];
    $ids = array_column($orders, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT order_id,product_id,product_name,price,quantity,line_total FROM order_items WHERE order_id IN ($in) ORDER BY id");
    $st->execute($ids);
    $by = [];
    foreach ($st->fetchAll() as $r) $by[$r['order_id']][] = $r;
    foreach ($orders as &$o) { $o['items'] = $by[$o['id']] ?? []; unset($o['id']); }
    unset($o);
    return $orders;
}
function restockOrder(PDO $pdo, $orderId) {
    $pdo->prepare('UPDATE products p JOIN order_items oi ON oi.product_id = p.id SET p.stock = p.stock + oi.quantity WHERE oi.order_id = ?')->execute([$orderId]);
}
function startCustomerSession($id) {
    session_regenerate_id(true);
    $_SESSION['customer_id'] = (int)$id;
}

$action = $_GET['action'] ?? '';

try {
    ensureSchema($pdo);
    seedDatabase($pdo);

    if ($action === 'login') {
        $data = body();
        $username = trim($data['username'] ?? '');
        $password = $data['password'] ?? '';
        if ($username === '' || $password === '') respond(['success'=>false,'message'=>'Username and password are required.'],400);

        $stmt = $pdo->prepare('SELECT id,username,name,password_hash FROM cashiers WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $cashier = $stmt->fetch();
        if (!$cashier || !password_verify($password, $cashier['password_hash'])) {
            respond(['success'=>false,'message'=>'Invalid cashier credentials.'],401);
        }
        unset($cashier['password_hash']);
        session_regenerate_id(true);
        $_SESSION['cashier_id'] = (int)$cashier['id'];
        respond(['success'=>true,'cashier'=>$cashier]);
    }

    if ($action === 'register') {
        $data = body();
        $name = trim($data['name'] ?? '');
        $username = trim($data['username'] ?? '');
        $password = (string)($data['password'] ?? '');

        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) respond(['success'=>false,'message'=>'Full name must be 2-120 characters.'],400);
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) respond(['success'=>false,'message'=>'Username must be 3-50 characters: letters, numbers, dot, dash or underscore.'],400);
        if (strlen($password) < 8) respond(['success'=>false,'message'=>'Password must be at least 8 characters.'],400);

        $stmt = $pdo->prepare('SELECT 1 FROM cashiers WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        if ($stmt->fetchColumn()) respond(['success'=>false,'message'=>'That username is already taken.'],409);

        try {
            $stmt = $pdo->prepare('INSERT INTO cashiers (username,name,password_hash) VALUES (?,?,?)');
            $stmt->execute([$username,$name,password_hash($password, PASSWORD_DEFAULT)]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') respond(['success'=>false,'message'=>'That username is already taken.'],409);
            throw $e;
        }
        session_regenerate_id(true);
        $_SESSION['cashier_id'] = (int)$pdo->lastInsertId();
        respond(['success'=>true,'cashier'=>['id'=>(int)$_SESSION['cashier_id'],'username'=>$username,'name'=>$name]]);
    }

    if ($action === 'products') {
        $stmt = $pdo->query('SELECT id,name,price,stock,category,image FROM products ORDER BY id');
        respond(['success'=>true,'products'=>$stmt->fetchAll()]);
    }

    if ($action === 'orders') {
        $stmt = $pdo->query("SELECT o.order_code,o.order_type,o.status,o.customer_name,o.subtotal,o.vat,o.total,o.payment_method,o.cash_received,o.change_amount,o.payment_reference,o.created_at,COALESCE(c.name,'-') AS cashier_name FROM orders o LEFT JOIN cashiers c ON c.id=o.cashier_id ORDER BY o.id DESC LIMIT 100");
        respond(['success'=>true,'orders'=>$stmt->fetchAll()]);
    }

    if ($action === 'checkout') {
        $data = body();
        $cashierId = (int)($data['cashier_id'] ?? 0);
        $customer = trim($data['customer_name'] ?? '') ?: 'Walk-in Customer';
        $payment = $data['payment_method'] ?? 'Cash';
        $items = $data['items'] ?? [];
        $cashReceived = $data['cash_received'] ?? null;
        $reference = trim($data['payment_reference'] ?? '');

        if ($cashierId <= 0 || !in_array($payment,['Cash','GCash','Card'],true) || !is_array($items) || count($items)===0) {
            respond(['success'=>false,'message'=>'Invalid checkout data.'],400);
        }
        if (($payment === 'GCash' || $payment === 'Card') && $reference === '') respond(['success'=>false,'message'=>'Payment reference is required.'],400);

        $pdo->beginTransaction();
        $subtotal = 0;
        $validatedItems = [];

        foreach ($items as $requested) {
            $productId = (int)($requested['id'] ?? 0);
            $qty = (int)($requested['quantity'] ?? 0);
            if ($productId <= 0 || $qty <= 0) throw new Exception('Invalid item quantity.');

            $stmt = $pdo->prepare('SELECT id,name,price,stock FROM products WHERE id=? FOR UPDATE');
            $stmt->execute([$productId]);
            $product = $stmt->fetch();
            if (!$product) throw new Exception('Product not found.');
            if ($qty > (int)$product['stock']) throw new Exception('Not enough stock for '.$product['name'].'. Available: '.$product['stock']);

            $line = (float)$product['price'] * $qty;
            $subtotal += $line;
            $validatedItems[] = [$product,$qty,$line];
        }

        $vat = round($subtotal * 0.12, 2);
        $total = round($subtotal + $vat, 2);
        $cash = $payment === 'Cash' ? (float)$cashReceived : null;
        $change = $payment === 'Cash' ? round($cash - $total,2) : null;
        if ($payment === 'Cash' && ($cash === null || $cash < $total)) throw new Exception('Cash received is not enough.');

        $code = orderCode();
        $stmt = $pdo->prepare('INSERT INTO orders (order_code,cashier_id,customer_name,subtotal,vat,total,payment_method,cash_received,change_amount,payment_reference) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$code,$cashierId,$customer,$subtotal,$vat,$total,$payment,$cash,$change,$reference ?: null]);
        $orderId = (int)$pdo->lastInsertId();

        $itemStmt = $pdo->prepare('INSERT INTO order_items (order_id,product_id,product_name,price,quantity,line_total) VALUES (?,?,?,?,?,?)');
        $stockStmt = $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id=?');
        foreach ($validatedItems as [$product,$qty,$line]) {
            $itemStmt->execute([$orderId,$product['id'],$product['name'],$product['price'],$qty,$line]);
            $stockStmt->execute([$qty,$product['id']]);
        }
        $pdo->commit();

        $stmt = $pdo->prepare('SELECT o.order_code AS orderId,o.customer_name AS customerName,o.subtotal,o.vat AS tax,o.total,o.payment_method AS paymentMethod,o.cash_received AS cashReceived,o.change_amount AS `change`,o.payment_reference AS paymentReference,o.created_at AS date,c.name AS cashierName FROM orders o LEFT JOIN cashiers c ON c.id=o.cashier_id WHERE o.id=?');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        $stmt = $pdo->prepare('SELECT product_id AS id,product_name AS name,price,quantity,line_total AS lineTotal FROM order_items WHERE order_id=? ORDER BY id');
        $stmt->execute([$orderId]);
        $order['items'] = $stmt->fetchAll();
        respond(['success'=>true,'order'=>$order]);
    }

    // ============================================================ CUSTOMER PORTAL
    if ($action === 'customer_register') {
        requireAjax();
        $data = body();
        $name = trim($data['name'] ?? '');
        $username = trim($data['username'] ?? '');
        $phone = trim($data['phone'] ?? '');
        $password = (string)($data['password'] ?? '');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) respond(['success'=>false,'message'=>'Full name must be 2-120 characters.'],400);
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) respond(['success'=>false,'message'=>'Username must be 3-50 characters: letters, numbers, dot, dash or underscore.'],400);
        if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{7,30}$/', $phone)) respond(['success'=>false,'message'=>'Enter a valid phone number.'],400);
        if (strlen($password) < 8) respond(['success'=>false,'message'=>'Password must be at least 8 characters.'],400);
        $stmt = $pdo->prepare('SELECT 1 FROM customers WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        if ($stmt->fetchColumn()) respond(['success'=>false,'message'=>'That username is already taken.'],409);
        try {
            $stmt = $pdo->prepare('INSERT INTO customers (username,name,phone,password_hash) VALUES (?,?,?,?)');
            $stmt->execute([$username,$name,$phone ?: null,password_hash($password, PASSWORD_DEFAULT)]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') respond(['success'=>false,'message'=>'That username is already taken.'],409);
            throw $e;
        }
        $id = (int)$pdo->lastInsertId();
        startCustomerSession($id);
        respond(['success'=>true,'customer'=>['id'=>$id,'username'=>$username,'name'=>$name,'phone'=>$phone ?: null]]);
    }

    if ($action === 'customer_login') {
        requireAjax();
        $data = body();
        $username = trim($data['username'] ?? '');
        $password = (string)($data['password'] ?? '');
        if ($username === '' || $password === '') respond(['success'=>false,'message'=>'Username and password are required.'],400);
        $stmt = $pdo->prepare('SELECT id,username,name,phone,password_hash FROM customers WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $c = $stmt->fetch();
        if (!$c || !password_verify($password, $c['password_hash'])) respond(['success'=>false,'message'=>'Invalid username or password.'],401);
        unset($c['password_hash']);
        startCustomerSession($c['id']);
        respond(['success'=>true,'customer'=>$c]);
    }

    if ($action === 'customer_me') {
        $id = (int)($_SESSION['customer_id'] ?? 0);
        $c = null;
        if ($id > 0) {
            $stmt = $pdo->prepare('SELECT id,username,name,phone FROM customers WHERE id=?');
            $stmt->execute([$id]);
            $c = $stmt->fetch() ?: null;
        }
        respond(['success'=>true,'customer'=>$c]);
    }

    if ($action === 'customer_logout') {
        unset($_SESSION['customer_id']);
        respond(['success'=>true]);
    }

    if ($action === 'cashier_logout') {
        unset($_SESSION['cashier_id']);
        respond(['success'=>true]);
    }

    if ($action === 'customer_orders') {
        $cust = requireCustomer($pdo);
        respond(['success'=>true,'orders'=>ordersWithItems($pdo, 'customer_id = ?', [$cust['id']])]);
    }

    if ($action === 'customer_checkout') {
        requireAjax();
        $cust = requireCustomer($pdo);
        $data = body();
        $payment = $data['payment_method'] ?? 'Cash';
        $items = $data['items'] ?? [];
        $reference = trim($data['payment_reference'] ?? '');
        if (!in_array($payment,['Cash','GCash','Card'],true) || !is_array($items) || count($items)===0) respond(['success'=>false,'message'=>'Your cart is empty or the payment method is invalid.'],400);
        if ($payment !== 'Cash' && $reference === '') respond(['success'=>false,'message'=>'Payment reference is required for '.$payment.'.'],400);

        $merged = [];
        foreach ($items as $it) {
            $pid = (int)($it['id'] ?? 0);
            $qty = (int)($it['quantity'] ?? 0);
            if ($pid <= 0 || $qty <= 0 || $qty > 1000) throw new Exception('Invalid item quantity.');
            $merged[$pid] = ($merged[$pid] ?? 0) + $qty;
        }
        ksort($merged);

        $pdo->beginTransaction();
        $subtotal = 0;
        $lines = [];
        foreach ($merged as $pid => $qty) {
            $stmt = $pdo->prepare('SELECT id,name,price,stock FROM products WHERE id=? FOR UPDATE');
            $stmt->execute([$pid]);
            $p = $stmt->fetch();
            if (!$p) throw new Exception('A product in your cart no longer exists.');
            if ($qty > (int)$p['stock']) throw new Exception('Not enough stock for '.$p['name'].'. Available: '.$p['stock']);
            $line = round((float)$p['price'] * $qty, 2);
            $subtotal += $line;
            $lines[] = [$p,$qty,$line];
        }
        $subtotal = round($subtotal, 2);
        $vat = round($subtotal * 0.12, 2);
        $total = round($subtotal + $vat, 2);
        $code = orderCode();
        $stmt = $pdo->prepare("INSERT INTO orders (order_code,cashier_id,customer_id,order_type,status,customer_name,subtotal,vat,total,payment_method,payment_reference) VALUES (?,NULL,?,'online','Pending',?,?,?,?,?,?)");
        $stmt->execute([$code,$cust['id'],$cust['name'],$subtotal,$vat,$total,$payment,$reference ?: null]);
        $orderId = (int)$pdo->lastInsertId();
        $itemStmt = $pdo->prepare('INSERT INTO order_items (order_id,product_id,product_name,price,quantity,line_total) VALUES (?,?,?,?,?,?)');
        $stockStmt = $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id=?');
        foreach ($lines as [$p,$qty,$line]) {
            $itemStmt->execute([$orderId,$p['id'],$p['name'],$p['price'],$qty,$line]);
            $stockStmt->execute([$qty,$p['id']]);
        }
        $pdo->commit();
        $order = ordersWithItems($pdo, 'order_code = ?', [$code], 1)[0];
        respond(['success'=>true,'order'=>$order]);
    }

    if ($action === 'customer_cancel') {
        requireAjax();
        $cust = requireCustomer($pdo);
        $code = trim(body()['order_code'] ?? '');
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT id,status FROM orders WHERE order_code=? AND customer_id=? FOR UPDATE');
        $stmt->execute([$code,$cust['id']]);
        $o = $stmt->fetch();
        if (!$o) throw new Exception('Order not found.');
        if ($o['status'] !== 'Pending') throw new Exception('Only pending orders can be cancelled.');
        restockOrder($pdo, $o['id']);
        $pdo->prepare("UPDATE orders SET status='Cancelled' WHERE id=?")->execute([$o['id']]);
        $pdo->commit();
        respond(['success'=>true]);
    }

    // ============================================================ CASHIER: manage online orders
    if ($action === 'order_status') {
        requireAjax();
        requireCashier();
        $data = body();
        $code = trim($data['order_code'] ?? '');
        $new = $data['status'] ?? '';
        if (!in_array($new, ['Processing','Completed','Cancelled'], true)) respond(['success'=>false,'message'=>'Invalid status.'],400);
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT id,status FROM orders WHERE order_code=? AND order_type='online' FOR UPDATE");
        $stmt->execute([$code]);
        $o = $stmt->fetch();
        if (!$o) throw new Exception('Online order not found.');
        if (in_array($o['status'], ['Completed','Cancelled'], true)) throw new Exception('This order is already closed.');
        if ($new === 'Processing' && $o['status'] !== 'Pending') throw new Exception('Only pending orders can move to Processing.');
        if ($new === 'Cancelled') restockOrder($pdo, $o['id']);
        $pdo->prepare('UPDATE orders SET status=? WHERE id=?')->execute([$new,$o['id']]);
        $pdo->commit();
        respond(['success'=>true]);
    }

    respond(['success'=>false,'message'=>'Unknown action.'],404);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respond(['success'=>false,'message'=>$e->getMessage()],400);
}
