<?php

function ensureSchema(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS cashiers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL UNIQUE,
        name VARCHAR(150) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS customers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL UNIQUE,
        name VARCHAR(150) NOT NULL,
        phone VARCHAR(30) NULL,
        password_hash VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS products (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        price DECIMAL(10,2) NOT NULL,
        stock INT NOT NULL DEFAULT 0,
        category VARCHAR(80) NOT NULL,
        image TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_code VARCHAR(30) NOT NULL UNIQUE,
        cashier_id INT NULL,
        customer_id INT NULL,
        order_type ENUM('pos','online') NOT NULL DEFAULT 'pos',
        status ENUM('Pending','Processing','Completed','Cancelled') NOT NULL DEFAULT 'Completed',
        customer_name VARCHAR(150) NOT NULL DEFAULT 'Walk-in Customer',
        subtotal DECIMAL(10,2) NOT NULL,
        vat DECIMAL(10,2) NOT NULL,
        total DECIMAL(10,2) NOT NULL,
        payment_method ENUM('Cash','GCash','Card') NOT NULL,
        cash_received DECIMAL(10,2) NULL,
        change_amount DECIMAL(10,2) NULL,
        payment_reference VARCHAR(150) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_orders_cashier FOREIGN KEY (cashier_id) REFERENCES cashiers(id),
        CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS order_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        product_id INT NOT NULL,
        product_name VARCHAR(150) NOT NULL,
        price DECIMAL(10,2) NOT NULL,
        quantity INT NOT NULL,
        line_total DECIMAL(10,2) NOT NULL,
        CONSTRAINT fk_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
        CONSTRAINT fk_items_product FOREIGN KEY (product_id) REFERENCES products(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $cols = [];
    foreach ($pdo->query('SHOW COLUMNS FROM orders')->fetchAll() as $c) $cols[$c['Field']] = $c;
    if (!isset($cols['customer_id'])) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN customer_id INT NULL');
        try { $pdo->exec('ALTER TABLE orders ADD CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL'); } catch (Throwable $e) {}
    }
    if (!isset($cols['order_type'])) $pdo->exec("ALTER TABLE orders ADD COLUMN order_type ENUM('pos','online') NOT NULL DEFAULT 'pos'");
    if (!isset($cols['status'])) $pdo->exec("ALTER TABLE orders ADD COLUMN status ENUM('Pending','Processing','Completed','Cancelled') NOT NULL DEFAULT 'Completed'");
    if (isset($cols['cashier_id']) && $cols['cashier_id']['Null'] === 'NO') $pdo->exec('ALTER TABLE orders MODIFY cashier_id INT NULL');
}

function seedDatabase(PDO $pdo) {
    if ((int)$pdo->query('SELECT COUNT(*) FROM cashiers')->fetchColumn() === 0) {
        $cashiers = [
            ['john.aquino', 'John Aquino'],
            ['lisa.garcia', 'Lisa Garcia'],
            ['mark.santos', 'Mark Santos'],
            ['rose.mendoza', 'Rose Mendoza'],
            ['kevin.reyes', 'Kevin Reyes'],
            ['grace.cruz', 'Grace Cruz'],
            ['paul.torres', 'Paul Torres'],
            ['nina.ramos', 'Nina Ramos'],
            ['eric.flores', 'Eric Flores'],
            ['jenny.navarro', 'Jenny Navarro'],
        ];
        $hash = '$2y$12$gFEmkRENVZe7jZQLIBem3.93dR/c0y0FbNYxCEQXrW6nnXqOg.u2i';
        $stmt = $pdo->prepare('INSERT INTO cashiers (username,name,password_hash) VALUES (?,?,?)');
        foreach ($cashiers as [$username, $name]) $stmt->execute([$username, $name, $hash]);
    }

    if ((int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() === 0) {
        $products = [
            [1, 'Chicken Rice Meal', 120.00, 50, 'Meals', 'https://images.unsplash.com/photo-1512058564366-18510be2db19?w=500'],
            [2, 'Beef Burger', 150.00, 40, 'Snacks', 'https://images.unsplash.com/photo-1568901346375-23c9450c58cd?w=500'],
            [3, 'Pancit Canton', 100.00, 60, 'Noodles', 'https://images.unsplash.com/photo-1552611052-33e04de081de?w=500'],
            [4, 'Fried Chicken', 135.00, 45, 'Meals', 'https://images.unsplash.com/photo-1626082927389-6cd097cdc6ec?w=500'],
            [5, 'Spaghetti', 110.00, 55, 'Pasta', 'https://images.unsplash.com/photo-1621996346565-e3dbc646d9a9?w=500'],
            [6, 'Chicken Sandwich', 95.00, 35, 'Snacks', 'https://images.unsplash.com/photo-1553909489-cd47e0907980?w=500'],
            [7, 'French Fries', 80.00, 70, 'Snacks', 'https://images.unsplash.com/photo-1573080496219-bb080dd4f877?w=500'],
            [8, 'Iced Coffee', 75.00, 80, 'Beverages', 'https://images.unsplash.com/photo-1461023058943-07fcbe16d735?w=500'],
            [9, 'Soft Drink', 50.00, 100, 'Beverages', 'https://images.unsplash.com/photo-1544145945-f90425340c7e?w=500'],
            [10, 'Fruit Shake', 90.00, 65, 'Beverages', 'https://images.unsplash.com/photo-1505252585461-04db1eb84625?w=500'],
        ];
        $stmt = $pdo->prepare('INSERT INTO products (id,name,price,stock,category,image) VALUES (?,?,?,?,?,?)');
        foreach ($products as $p) $stmt->execute($p);
    }
}
