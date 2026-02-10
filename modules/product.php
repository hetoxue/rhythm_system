<?php

/**
 * 商品管理相关接口
 */

require_once __DIR__ . '/../functions.php';

/**
 * 接口分发
 */
function handle_product_action(string $action): void
{
    switch ($action) {
        case 'admin_list':
            api_admin_product_list();
            break;
        case 'admin_save':
            api_admin_product_save();
            break;
        case 'admin_delete':
            api_admin_product_delete();
            break;
        case 'list':
            api_product_list();
            break;
        case 'buy':
            api_product_buy();
            break;
        case 'admin_orders':
            api_admin_product_orders();
            break;
        case 'my_orders':
            api_product_my_orders();
            break;
        default:
            json_error('未知 action：' . $action);
    }
}

/**
 * [管理员] 获取商品列表
 */
function api_admin_product_list(): void
{
    $admin = current_admin();
    if (!$admin) json_error('需要管理员权限', 401);

    $page = (int)input('page', 1);
    $pageSize = (int)input('page_size', 20);
    $offset = ($page - 1) * $pageSize;

    $total = db_fetch_one('SELECT COUNT(*) as c FROM products')['c'];
    $list = db_fetch_all("SELECT * FROM products ORDER BY sort_order DESC, id DESC LIMIT $offset, $pageSize");

    json_response(['total' => $total, 'list' => $list]);
}

/**
 * [管理员] 保存商品 (新增/编辑)
 */
function api_admin_product_save(): void
{
    $admin = current_admin();
    if (!$admin) json_error('需要管理员权限', 401);

    $id = (int)input('id', 0);
    $name = input('name');
    $price = (int)input('price', 0); // 单位：分
    $stock = (int)input('stock', 0);
    $status = (int)input('status', 1);
    $sort = (int)input('sort_order', 0);
    $image_url = input('image_url', '');
    $description = input('description', '');

    if (!$name) json_error('商品名称不能为空');
    if ($price < 0) json_error('价格不能为负数');
    if ($stock < 0) json_error('库存不能为负数');

    if ($id > 0) {
        // 更新
        $sql = "UPDATE products SET name=?, price=?, stock=?, status=?, sort_order=?, image_url=?, description=?, updated_at=NOW() WHERE id=?";
        db_execute($sql, [$name, $price, $stock, $status, $sort, $image_url, $description, $id]);
        add_operation_log(2, $admin['id'], 'update_product', ['id' => $id, 'name' => $name]);
    } else {
        // 新增
        $sql = "INSERT INTO products (name, price, stock, status, sort_order, image_url, description, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        db_execute($sql, [$name, $price, $stock, $status, $sort, $image_url, $description]);
        $id = db_last_insert_id();
        add_operation_log(2, $admin['id'], 'create_product', ['id' => $id, 'name' => $name]);
    }

    json_response(['id' => $id], 0, '保存成功');
}

/**
 * [管理员] 删除商品
 */
function api_admin_product_delete(): void
{
    $admin = current_admin();
    if (!$admin) json_error('需要管理员权限', 401);

    $id = (int)input('id', 0);
    if ($id <= 0) json_error('参数错误');

    // 检查是否存在
    $prod = db_fetch_one('SELECT name FROM products WHERE id=?', [$id]);
    if (!$prod) json_error('商品不存在');

    db_execute('DELETE FROM products WHERE id=?', [$id]);
    add_operation_log(2, $admin['id'], 'delete_product', ['id' => $id, 'name' => $prod['name']]);
    
    json_response(null, 0, '删除成功');
}

/**
 * [用户/公共] 获取上架商品列表
 */
function api_product_list(): void
{
    // 获取列表，通常不需要分页或者简单分页，这里返回所有上架的
    $list = db_fetch_all("SELECT * FROM products WHERE status=1 ORDER BY sort_order DESC, id DESC");
    json_response($list);
}

/**
 * [用户] 购买商品
 */
function api_product_buy(): void
{
    $user = current_user();
    if (!$user) json_error('未登录', 401);

    $productId = (int)input('product_id', 0);
    $quantity = (int)input('quantity', 1);
    $payMethod = input('pay_method', 'balance'); // balance | offline

    if ($productId <= 0 || $quantity <= 0) json_error('参数错误');
    if (!in_array($payMethod, ['balance', 'offline'])) json_error('支付方式无效');

    // 检查商品是否存在
    $product = db_fetch_one('SELECT * FROM products WHERE id=?', [$productId]);
    if (!$product) json_error('商品不存在');
    if ($product['status'] == 0) json_error('商品已下架');
    if ($product['stock'] < $quantity) json_error('库存不足，剩余 ' . $product['stock']);

    $totalPrice = $product['price'] * $quantity;

    $pdo = db();
    $pdo->beginTransaction();

    try {
        // 根据支付方式处理
        if ($payMethod === 'balance') {
            // 锁定用户余额
            $uInfo = db_fetch_one('SELECT balance FROM users WHERE id=? FOR UPDATE', [$user['id']]);
            if ($uInfo['balance'] < $totalPrice) {
                throw new Exception('余额不足，需支付 ' . number_format($totalPrice/100, 2) . ' 元');
            }
            
            // 扣费
            $newBalance = $uInfo['balance'] - $totalPrice;
            db_execute('UPDATE users SET balance=?, updated_at=NOW() WHERE id=?', [$newBalance, $user['id']]);
            
            // 记录消费流水
            db_execute(
                'INSERT INTO consume_records (user_id, type, related_id, amount, balance_after, remark, created_at) VALUES (?, 3, ?, ?, ?, ?, NOW())',
                [$user['id'], $productId, $totalPrice, $newBalance, '购买商品：' . $product['name'] . " x$quantity"]
            );
        } else {
            // 线下支付，只记录订单
        }

        // 扣减库存
        db_execute('UPDATE products SET stock=? WHERE id=?', [$product['stock'] - $quantity, $productId]);

        // 创建订单记录
        db_execute(
            'INSERT INTO product_orders (user_id, product_name, product_price, quantity, total_amount, pay_method, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [
                $user['id'],
                $product['name'],
                $product['price'],
                $quantity,
                $totalPrice,
                $payMethod
            ]
        );

        $pdo->commit();
        json_response(['balance' => $newBalance ?? $user['balance']], 0, '购买成功');
    } catch (Exception $e) {
        $pdo->rollBack();
        json_error($e->getMessage());
    }
}

/**
 * [管理员] 获取商品订单记录
 */
function api_admin_product_orders(): void
{
    $admin = current_admin();
    if (!$admin) json_error('需要管理员权限', 401);

    $page = (int)input('page', 1);
    $pageSize = (int)input('page_size', 20);
    $offset = ($page - 1) * $pageSize;

    // 检查表是否存在
    $tableExists = db_fetch_one("SHOW TABLES LIKE 'product_orders'");
    if (!$tableExists) {
        json_response(['total' => 0, 'list' => []]);
    }

    // 联表查询用户手机号
    $sql = "SELECT o.*, u.mobile, u.qq
            FROM product_orders o
            LEFT JOIN users u ON o.user_id = u.id
            ORDER BY o.id DESC LIMIT $offset, $pageSize";
    $list = db_fetch_all($sql);
    
    $total = db_fetch_one('SELECT COUNT(*) as c FROM product_orders')['c'];

    json_response(['total' => $total, 'list' => $list]);
}

/**
 * [用户] 我的商品订单
 */
function api_product_my_orders(): void
{
    $user = current_user();
    if (!$user) json_error('未登录', 401);
    
    $list = db_fetch_all("SELECT * FROM product_orders WHERE user_id=? ORDER BY id DESC LIMIT 50", [$user['id']]);
    json_response($list);
}
