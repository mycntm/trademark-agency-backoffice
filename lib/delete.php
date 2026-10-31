<?php
/**
 * 真·物理删除（订单 / 询价工单 / 客户）
 *
 * 背景：系统默认走软删除（documents.deleted_at / orders.voided_at / enquiries.deleted_at），
 * 理由是「客户几年后回来找受理通知书，必须有」。但测试数据、重复建的单、无效客户
 * 需要真删除，软删会一直堆在列表里——所以这里提供不可逆的物理删除。
 *
 * 安全管理（三层）：
 *  1) 权限：viewer 不能删（admin_can_hard_delete）
 *  2) 前置校验：有未作废订单的工单不给删；有订单/工单的客户默认不给删（除非显式勾选级联）
 *  3) 确认：界面要求输入编号/邮箱二次确认，服务端再校验一次（confirm_text 必须完全匹配）
 *
 * 删除内容：关联表全部清干净（order_events / payments / messages / documents + 磁盘文件 /
 *           quotes / auth_tokens），不留孤儿行。
 * 留痕：写 admin_log（谁删的、删了什么、级联删了多少条）+ app.log。
 */

if (!function_exists('admin_can_hard_delete')) {
    /**
     * 谁能做物理删除：owner 与 staff 可以，viewer 不行。
     * （真删除不可逆，只读账号必须挡在外面。）
     */
    function admin_can_hard_delete()
    {
        $u = admin_user();
        if (!$u) return false;
        $role = isset($u['role']) ? $u['role'] : 'staff';
        return in_array($role, ['owner', 'staff'], true);
    }
}

/** 删除一个附件对应的磁盘文件（失败不影响数据库清理） */
if (!function_exists('hard_delete_doc_file')) {
    function hard_delete_doc_file(array $doc)
    {
        $name = (string)($doc['stored_name'] ?? '');
        if ($name === '') return false;
        $file = storage_path() . DIRECTORY_SEPARATOR . basename($name);
        if (is_file($file)) { @unlink($file); return true; }
        return false;
    }
}

/**
 * 物理删除订单（含全部关联数据）
 *
 * @return array ['ok'=>bool, 'error'=>string, 'stats'=>array]
 */
if (!function_exists('hard_delete_order')) {
    function hard_delete_order($id)
    {
        $id = (int)$id;
        $o = db_one('SELECT * FROM orders WHERE id = ?', [$id]);
        if (!$o) return ['ok' => false, 'error' => 'order not found', 'stats' => []];

        $stats = [
            'ref'      => (string)$o['ref'],
            'client'   => '',
            'events'   => 0,
            'payments' => 0,
            'messages' => 0,
            'docs'     => 0,
            'files'    => 0,
        ];
        $cl = db_one('SELECT email FROM clients WHERE id = ?', [(int)$o['client_id']]);
        if ($cl) $stats['client'] = (string)$cl['email'];

        // 1) 附件：先删磁盘文件，再删行（连同访问日志）
        $docs = db_all("SELECT * FROM documents WHERE owner_type='order' AND owner_id=?", [$id]);
        foreach ($docs as $d) {
            if (hard_delete_doc_file($d)) $stats['files']++;
            if (table_has_column('document_access_log', 'document_id')) {
                db_exec('DELETE FROM `document_access_log` WHERE document_id = ?', [(int)$d['id']]);
            }
        }
        if ($docs) {
            $stats['docs'] = (int)db_val("SELECT COUNT(*) FROM documents WHERE owner_type='order' AND owner_id=?", [$id]);
            db_exec("DELETE FROM documents WHERE owner_type='order' AND owner_id=?", [$id]);
            $stats['docs'] = count($docs);
        }

        // 2) 进度节点 / 付款 / 留言
        $stats['events']   = (int)db_val('SELECT COUNT(*) FROM order_events WHERE order_id=?', [$id]);
        db_exec('DELETE FROM order_events WHERE order_id=?', [$id]);

        $stats['payments'] = (int)db_val('SELECT COUNT(*) FROM payments WHERE order_id=?', [$id]);
        db_exec('DELETE FROM payments WHERE order_id=?', [$id]);

        $stats['messages'] = (int)db_val('SELECT COUNT(*) FROM messages WHERE order_id=?', [$id]);
        db_exec('DELETE FROM messages WHERE order_id=?', [$id]);

        // 3) 订单本体
        db_exec('DELETE FROM orders WHERE id=?', [$id]);

        admin_log_action('hard_delete', 'order', $id,
            $stats['ref'] . ' · ' . $stats['client']
            . ' (events:' . $stats['events'] . ' payments:' . $stats['payments']
            . ' messages:' . $stats['messages'] . ' docs:' . $stats['docs'] . ')');
        app_log('hard_delete order ' . $stats['ref'] . ' by admin #' . admin_id()
            . ' — events:' . $stats['events'] . ' payments:' . $stats['payments']
            . ' docs:' . $stats['docs'] . ' files:' . $stats['files'], 'WARN');

        return ['ok' => true, 'error' => '', 'stats' => $stats];
    }
}

/**
 * 物理删除询价工单（含附件 / 留言 / 报价）
 * 有未作废订单的工单拒绝删除——否则订单会失去来源，防重也会失效。
 */
if (!function_exists('hard_delete_enquiry')) {
    function hard_delete_enquiry($id)
    {
        $id = (int)$id;
        $e = db_one('SELECT * FROM enquiries WHERE id = ?', [$id]);
        if (!$e) return ['ok' => false, 'error' => 'enquiry not found', 'stats' => []];

        // 前置校验：名下有未作废订单
        $aliveCond = 'enquiry_id = ' . $id;
        if (table_has_column('orders', 'voided_at')) $aliveCond .= ' AND voided_at IS NULL';
        $orders = db_all("SELECT id, ref FROM orders WHERE $aliveCond", []);
        if ($orders) {
            $refs = [];
            foreach ($orders as $od) $refs[] = $od['ref'];
            return ['ok' => false, 'error' => 'has_orders', 'refs' => $refs, 'stats' => []];
        }

        $stats = ['id' => $id, 'email' => (string)$e['email'], 'docs' => 0, 'files' => 0, 'messages' => 0, 'quotes' => 0];

        $docs = db_all("SELECT * FROM documents WHERE owner_type='enquiry' AND owner_id=?", [$id]);
        foreach ($docs as $d) {
            if (hard_delete_doc_file($d)) $stats['files']++;
            if (table_has_column('document_access_log', 'document_id')) {
                db_exec('DELETE FROM `document_access_log` WHERE document_id = ?', [(int)$d['id']]);
            }
        }
        if ($docs) {
            db_exec("DELETE FROM documents WHERE owner_type='enquiry' AND owner_id=?", [$id]);
            $stats['docs'] = count($docs);
        }

        $stats['messages'] = (int)db_val('SELECT COUNT(*) FROM messages WHERE enquiry_id=?', [$id]);
        db_exec('DELETE FROM messages WHERE enquiry_id=?', [$id]);

        $stats['quotes'] = (int)db_val('SELECT COUNT(*) FROM quotes WHERE enquiry_id=?', [$id]);
        db_exec('DELETE FROM quotes WHERE enquiry_id=?', [$id]);

        db_exec('DELETE FROM enquiries WHERE id=?', [$id]);

        admin_log_action('hard_delete', 'enquiry', $id,
            '#' . $id . ' · ' . $stats['email']
            . ' (docs:' . $stats['docs'] . ' messages:' . $stats['messages'] . ' quotes:' . $stats['quotes'] . ')');
        app_log('hard_delete enquiry #' . $id . ' by admin #' . admin_id(), 'WARN');

        return ['ok' => true, 'error' => '', 'stats' => $stats];
    }
}

/**
 * 物理删除客户（含名下登陆令牌 / 附件）
 * 名下有订单或工单时默认拒绝；$cascade = true 才连带删除（勾选项）。
 */
if (!function_exists('hard_delete_client')) {
    function hard_delete_client($id, $cascade = false)
    {
        $id = (int)$id;
        $c = db_one('SELECT * FROM clients WHERE id = ?', [$id]);
        if (!$c) return ['ok' => false, 'error' => 'client not found', 'stats' => []];

        $orders    = db_all('SELECT id, ref FROM orders WHERE client_id=?', [$id]);
        $enquiries = db_all('SELECT id FROM enquiries WHERE client_id=?', [$id]);

        if (($orders || $enquiries) && !$cascade) {
            return ['ok' => false, 'error' => 'has_data',
                    'orders' => count($orders), 'enquiries' => count($enquiries), 'stats' => []];
        }

        $stats = ['email' => (string)$c['email'], 'orders' => 0, 'enquiries' => 0, 'docs' => 0, 'files' => 0];

        // 先清订单（含作废单——删客户就是彻底清干净）
        foreach ($orders as $od) {
            $r = hard_delete_order((int)$od['id']);
            if ($r['ok']) $stats['orders']++;
        }
        // 再清工单（订单已删，此时不会被 has_orders 拦住）
        foreach ($enquiries as $eq) {
            $r = hard_delete_enquiry((int)$eq['id']);
            if ($r['ok']) $stats['enquiries']++;
        }

        // 客户名下的附件 + 登录令牌
        $docs = db_all("SELECT * FROM documents WHERE owner_type='client' AND owner_id=?", [$id]);
        foreach ($docs as $d) {
            if (hard_delete_doc_file($d)) $stats['files']++;
            if (table_has_column('document_access_log', 'document_id')) {
                db_exec('DELETE FROM `document_access_log` WHERE document_id = ?', [(int)$d['id']]);
            }
        }
        if ($docs) {
            db_exec("DELETE FROM documents WHERE owner_type='client' AND owner_id=?", [$id]);
            $stats['docs'] = count($docs);
        }
        db_exec('DELETE FROM auth_tokens WHERE client_id=?', [$id]);
        db_exec('DELETE FROM clients WHERE id=?', [$id]);

        admin_log_action('hard_delete', 'client', $id,
            $stats['email'] . ' (orders:' . $stats['orders'] . ' enquiries:' . $stats['enquiries']
            . ' docs:' . $stats['docs'] . ')');
        app_log('hard_delete client ' . $stats['email'] . ' by admin #' . admin_id(), 'WARN');

        return ['ok' => true, 'error' => '', 'stats' => $stats];
    }
}

/** 客户名下的订单/工单数量（删除确认页用） */
if (!function_exists('client_data_counts')) {
    function client_data_counts($id)
    {
        return [
            'orders'    => (int)db_val('SELECT COUNT(*) FROM orders WHERE client_id=?', [(int)$id]),
            'enquiries' => (int)db_val('SELECT COUNT(*) FROM enquiries WHERE client_id=?', [(int)$id]),
        ];
    }
}
