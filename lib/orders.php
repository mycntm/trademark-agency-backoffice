<?php
/**
 * orders.php —— 客户、订单、无密码登录令牌、付款
 */

/** 取或建客户账号（账号由我们创建，客户不自行注册） */
if (!function_exists('upsert_client')) {
    function upsert_client($email, $name = '', $company = '', $country = '', $phone = '', $langPref = null)
    {
        $email = strtolower(trim($email));
        $row = db_one('SELECT * FROM clients WHERE email = ?', [$email]);
        if ($row) {
            // 补全信息
            $upd = [];
            $args = [];
            foreach (['name' => $name, 'company' => $company, 'country' => $country, 'phone' => $phone] as $k => $v) {
                if ($v !== '' && ($row[$k] === '' || $row[$k] === null)) { $upd[] = "`$k`=?"; $args[] = $v; }
            }
            if ($upd) { $args[] = $row['id']; db_exec('UPDATE clients SET ' . implode(',', $upd) . ' WHERE id=?', $args); }
            return (int)$row['id'];
        }
        return (int)db_exec(
            'INSERT INTO clients (email,name,company,country,phone,lang) VALUES (?,?,?,?,?,?)',
            [$email, $name, $company, $country, $phone, $langPref ?: lang()]
        );
    }
}

/**
 * magic link 有效期（分钟）。
 * 2026-10-15 从 24 小时放宽到 15 天（21600 分钟）：客户从报价沟通、付款到上传回单
 * 往往要数天甚至更久，24 小时常常干不完。改 config 的 security.token_ttl_min 即可，
 * 所有文案（邮件、后台卡片、登录页提示）会自动跟着变。
 */
if (!function_exists('token_ttl_minutes')) {
    function token_ttl_minutes()
    {
        $m = (int)cfg('security.token_ttl_min', 21600);
        return $m > 0 ? $m : 21600;
    }
}

/**
 * 把有效期渲染成人话："24 hours" / "45 minutes"。
 * 邮件和后台卡片都用它，避免改了配置文案还写着旧时长。
 */
if (!function_exists('token_ttl_text')) {
    function token_ttl_text()
    {
        $m = token_ttl_minutes();
        if ($m % 1440 === 0) {
            $d = $m / 1440;
            return $d . ($d == 1 ? ' day' : ' days');
        }
        if ($m % 60 === 0) {
            $h = $m / 60;
            return $h . ($h == 1 ? ' hour' : ' hours');
        }
        return $m . ($m == 1 ? ' minute' : ' minutes');
    }
}

/**
 * 令牌在有效期内是否可以重复消费（2026-09-30）
 *
 * 为什么默认改成「可以」：企业邮箱的安全网关（Microsoft Safe Links、
 * Proofpoint、Mimecast 等）收到邮件后会先自动访问正文里每一个链接做钓鱼扫描。
 * 一次性令牌会被这趟扫描替客户点掉，客户真正点开时只剩一句
 * 「链接已被使用」，订单页压根没打开 —— 这封邮件等于白发。
 *
 * 改成有效期内可重复消费后，扫描器点完客户再点照样进得去。
 * 代价：链接在有效期内的泄露窗口变大（拿到链接的人就能看这一单），
 * 与「密码重置链接」「网盘分享链接」是同一类取舍 —— 便捷换窗口。
 *
 * 想回到一次性：把 config.php 里的 security.token_reusable 设为 false 即可，
 * 不用改代码、不用跑迁移。
 */
if (!function_exists('token_is_reusable')) {
    function token_is_reusable()
    {
        return (bool)cfg('security.token_reusable', true);
    }
}

/**
 * 邮件 / 页面里那句「链接多久有效、能用几次」的说明（2026-09-30）
 *
 * 以前这句话在建单邮件、后台卡片、客户自助重发三处各写了一遍，
 * 策略一改就会出现「代码已经可重复用了、邮件里还写着只能用一次」。
 * 抽成一个函数，文案跟着 token_is_reusable() 自动变。
 */
if (!function_exists('token_link_note')) {
    function token_link_note($lang = 'en')
    {
        $ttl = token_ttl_text();
        if ($lang === 'zh') {
            return token_is_reusable()
                ? '链接 ' . $ttl . ' 内有效，期间可反复打开；过期后在登录页重新获取即可。'
                : '链接 ' . $ttl . ' 内有效且只能用一次；过期或换设备请在登录页重新获取。';
        }
        return token_is_reusable()
            ? 'This link is valid for ' . $ttl . ' and can be opened as many times as you like within that window. If it has expired, just request a new one on the sign-in page — we will email it to you straight away.'
            : 'This link is valid for ' . $ttl . ' and can be used once. If it has expired, just request a new one on the sign-in page — we will email it to you straight away.';
    }
}

/** 签发令牌（明文返回，库里只存 hash；是否一次性见 token_is_reusable()） */
if (!function_exists('issue_token')) {
    function issue_token($clientId, $purpose = 'login', $targetId = null)
    {
        $ttl = token_ttl_minutes();
        $plain = random_token(32);
        db_exec('INSERT INTO auth_tokens (client_id,token_hash,purpose,target_id,expires_at,ip) VALUES (?,?,?,?,DATE_ADD(NOW(), INTERVAL ? MINUTE),?)',
            [(int)$clientId, hash('sha256', $plain), $purpose, $targetId, $ttl, client_ip()]);
        return $plain;
    }
}

/**
 * 令牌 -> URL
 *
 * 三种 purpose 的落地页不一样，但 URL 形式可以相同 —— 区别不在链接长什么样，
 * 而在 account.php 消费令牌时读到的 purpose：
 *   quote_view → quote.php（客户看报价）
 *   login      → account.php（账户首页，客户自己找订单）
 *   order_view → account.php 但直接跳到 target_id 那张订单（2026-09-30 接上）
 * 也就是说 order_view 和 login 的 URL 长得一样，是 account.php 按 purpose 分流的。
 */
if (!function_exists('token_url')) {
    function token_url($plain, $purpose)
    {
        if ($purpose === 'quote_view') return site_url('quote.php?t=' . urlencode($plain));
        return site_url('account.php?t=' . urlencode($plain));
    }
}

/**
 * 消费令牌，返回整行（含 target_id）或 null
 *
 * 2026-09-30 新增：直达订单的链接需要知道"跳哪一单"，只拿 client_id 不够。
 * 抽成整行版本后 consume_token() 复用它，行为一字不变。
 *
 * 同一天又改了消费策略：默认「有效期内可重复消费」，一次性只是可选项
 * （见 token_is_reusable()）。不同 purpose 之间始终互不干扰，
 * 因为 purpose 是 WHERE 条件之一。
 */
if (!function_exists('consume_token_row')) {
    function consume_token_row($plain, $purpose = 'login')
    {
        if ($plain === '') return null;
        $hash = hash('sha256', $plain);
        /* 可重复消费（默认）：只按有效期判定，不看 used_at。
         * 一次性模式（security.token_reusable=false）才把 used_at IS NULL 拼回 WHERE。 */
        $sql = 'SELECT * FROM auth_tokens WHERE token_hash=? AND purpose=? AND expires_at > NOW()'
             . (token_is_reusable() ? '' : ' AND used_at IS NULL');
        $row = db_one($sql, [$hash, $purpose]);
        if (!$row) return null;
        /* used_at 记的是「首次使用时间」，不是「失效时间」——
         * 可重复消费模式下每点一次都会走到这里，但只在第一次写入，
         * 这样后台还能看出「这封邮件客户到底点没点开过」，
         * 否则一旦改成可重复消费，这个字段就永远是空的了。 */
        db_exec('UPDATE auth_tokens SET used_at=NOW() WHERE id=? AND used_at IS NULL', [$row['id']]);
        return $row;
    }
}

/** 消费令牌，返回 client_id 或 null */
if (!function_exists('consume_token')) {
    function consume_token($plain, $purpose = 'login')
    {
        $row = consume_token_row($plain, $purpose);
        return $row ? (int)$row['client_id'] : null;
    }
}

/**
 * 签发「直达某张订单」的链接（2026-09-30）
 *
 * 客户收到邮件点一下 → 自动登录 → 直接落在这一单的详情页，
 * 付款按钮就在眼前，不用先看账户首页再自己找是哪一单。
 *
 * 链接在有效期内可反复打开（2026-09-30，见 token_is_reusable()）：
 * 邮件安全网关的自动扫描会先把链接访问一遍，若是一次性的，
 * 客户真正点开时只能看到「已被使用」，这封催付邮件就白发了。
 * 点开一次后 session 已建立，24 小时内从任意入口进 account.php 都不用再点邮件；
 * 超过有效期才需要凭邮箱重发。
 *
 * @return string 完整 URL
 */
if (!function_exists('order_deeplink')) {
    function order_deeplink($clientId, $orderId)
    {
        return token_url(issue_token((int)$clientId, 'order_view', (int)$orderId), 'order_view');
    }
}

/** 添加订单进度事件 */
if (!function_exists('add_order_event')) {
    function add_order_event($orderId, $stage, $titleEn = '', $titleZh = '', $note = '', $visible = 1)
    {
        // admin_id 是 003 才加的列：没跑迁移就不写，避免 Unknown column
        $cols = ['order_id', 'stage', 'title_en', 'title_zh', 'note', 'visible'];
        $vals = [(int)$orderId, $stage, $titleEn, $titleZh, $note, $visible ? 1 : 0];
        if (table_has_column('order_events', 'admin_id')) {
            $cols[] = 'admin_id';
            $vals[] = admin_id();      // 系统自动产生的事件（如 webhook）这里是 0
        }
        return (int)db_exec(
            'INSERT INTO order_events (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')',
            $vals
        );
    }
}

/**
 * 报价是否已过期（2026-09-30 新增）
 *
 * valid_until 是 DATE 且「含当天」：2026-09-30 表示 10-01 当天仍可成交，10-02 起失效。
 * 空值 / 0000-00-00 = 长期有效，不算过期。
 *
 * 为什么必须有这个校验：报价里写着官费与服务费的金额，过期意味着我们的成本口径
 * （汇率、官费调整、翻译量）已经变了。之前客户拿着三个月前的旧报价点「接受」也能
 * 直接生成订单并且按旧价付款 —— 钱就这么被锁死在旧价上。
 */
if (!function_exists('quote_is_expired')) {
    function quote_is_expired($quote)
    {
        if (!$quote || empty($quote['valid_until'])) return false;
        $vu = substr((string)$quote['valid_until'], 0, 10);
        if ($vu === '' || $vu === '0000-00-00') return false;
        // Y-m-d 定长格式，字典序即时间序
        return $vu < date('Y-m-d');
    }
}

/**
 * 由工单 + 报价生成订单，并开通客户账号
 * @return int order id
 */
if (!function_exists('create_order_from_enquiry')) {
    function create_order_from_enquiry($enquiryId, $quoteId, $extra = [])
    {
        $enq = db_one('SELECT * FROM enquiries WHERE id = ?', [(int)$enquiryId]);
        $q   = db_one('SELECT * FROM quotes WHERE id = ?', [(int)$quoteId]);
        if (!$enq) throw new Exception('enquiry not found');

        // 过期报价不许成交：后台「生成订单」和客户「接受报价」两个入口都走这里，
        // 收口在这一处最省事，也不会漏掉将来新增的入口。
        if (quote_is_expired($q)) {
            throw new Exception('quote ' . ($q ? $q['ref'] : '#'.$quoteId)
                . ' expired on ' . substr((string)$q['valid_until'], 0, 10));
        }

        // 幂等防重：同一询价已存在「未作废」订单时不再新建（作废单允许重建）。
        // 2026-09-29 线上出现同一报价重复建单（ORD-0003 / ORD-0004）——
        // 后台「生成订单」和客户「接受报价」两个入口都会走到这里，之前没有任何检查。
        $dupeCond = 'enquiry_id = ' . (int)$enq['id'];
        if (table_has_column('orders', 'voided_at')) $dupeCond .= ' AND voided_at IS NULL';
        $dupe = db_one("SELECT id, ref FROM orders WHERE $dupeCond ORDER BY id DESC LIMIT 1", []);
        if ($dupe) throw new Exception('order already exists for this enquiry: ' . $dupe['ref']);

        $official = $q ? (float)$q['official_fee'] : 0;
        $service  = $q ? (float)$q['service_fee'] : (float)$enq['est_price'];
        $trans    = $q ? (float)$q['translation_fee'] : (float)setting('translation_fee', cfg('pricing.translation_fee', 30));
        $total    = $q ? (float)$q['total'] : ($official + $service + $trans);
        $cur      = $q ? $q['currency'] : $enq['currency'];

        // 1) 客户账号
        $clientId = (int)$enq['client_id'];
        if (!$clientId) {
            $clientId = upsert_client($enq['email'], $enq['name'], $enq['company'], $enq['country'], $enq['phone'], $enq['lang']);
            db_exec('UPDATE enquiries SET client_id=? WHERE id=?', [$clientId, $enq['id']]);
        }

        // 2) 订单
        $ref = next_ref('orders', 'ORD');
        $registrantName  = isset($extra['registrant_name']) ? $extra['registrant_name'] : ($enq['company'] ?: $enq['name']);
        $registrantAddr  = isset($extra['registrant_addr']) ? $extra['registrant_addr'] : '';
        $registrantType  = $enq['applicant_type'] ?: 'company';

        // 办理事项 + 已有商标号：平滑迁移——线上库跑过 002 才有这两列，没跑也不影响建单
        $svcKey = isset($enq['service_type']) ? (string)$enq['service_type'] : '';
        if ($svcKey === '') $svcKey = 'registration';
        $regNo  = isset($enq['existing_reg_no']) ? (string)$enq['existing_reg_no'] : '';

        $cols = ['ref','client_id','enquiry_id','quote_id','mark_name','mark_type','device_file_id',
                 'registrant_name','registrant_addr','registrant_type','classes_json',
                 'official_fee','service_fee','translation_fee','total','currency'];
        $vals = [
            $ref, $clientId, $enq['id'], $q ? $q['id'] : null,
            $enq['mark_name'], $enq['mark_type'], $enq['device_file_id'],
            $registrantName, $registrantAddr, $registrantType,
            $enq['classes_json'],
            $official, $service, $trans, $total, $cur,
        ];
        if (table_has_column('orders', 'service_type')) {
            $cols[] = 'service_type';     $vals[] = $svcKey;
        }
        if (table_has_column('orders', 'existing_reg_no')) {
            $cols[] = 'existing_reg_no';  $vals[] = mb_substr($regNo, 0, 40);
        }

        /* 撞号重试（2026-09-30）
         * next_ref() 用 GET_LOCK 串行化取号，但「锁释放 → 本 INSERT」之间仍有极小窗口。
         * orders.ref 上有 UNIQUE KEY，撞了会抛 23000；与其让客户看 500，
         * 不如重取一个号再来一次（最多 4 次）。$vals[0] 就是 ref。 */
        $orderId = 0;
        for ($attempt = 0; $attempt < 4; $attempt++) {
            try {
                $orderId = (int)db_exec(
                    'INSERT INTO orders (`' . implode('`,`', $cols) . '`,status)'
                    . ' VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ',"pending_payment")',
                    $vals
                );
                break;
            } catch (PDOException $e) {
                if (!is_duplicate_key($e) || $attempt === 3) throw $e;
                $vals[0] = next_ref('orders', 'ORD');
                app_log('create_order_from_enquiry: ref collision, retrying with ' . $vals[0], 'WARN');
            }
        }

        // 3) 工单状态
        db_exec("UPDATE enquiries SET status='confirmed' WHERE id=?", [$enq['id']]);
        if ($q) db_exec("UPDATE quotes SET status='accepted', accepted_at=NOW() WHERE id=?", [$q['id']]);

        // 4) 首个进度事件
        add_order_event($orderId, 'pending_payment',
            'Order created — awaiting payment', '订单已建立，待付款', '', 1);

        return $orderId;
    }
}

/**
 * 「未删除的工单」这个条件的 SQL 片段。
 * 003 迁移没跑时（没有 deleted_at 列）返回空串，列表就退化成「全部可见」——
 * 宁可多显示，也不能因为列不存在让整个后台 500。
 */
if (!function_exists('enq_alive_sql')) {
    function enq_alive_sql($alias = '')
    {
        if (!table_has_column('enquiries', 'deleted_at')) return '';
        $p = $alias !== '' ? ('`' . str_replace('`', '', $alias) . '`.') : '';
        return $p . '`deleted_at` IS NULL';
    }
}

/**
 * 「未删除的进度节点」SQL 片段（与 enq_alive_sql 同一套思路）。
 * 003 没跑（无 deleted_at 列）时返回空串，列表退化成全部可见，不 500。
 */
if (!function_exists('evt_alive_sql')) {
    function evt_alive_sql($alias = '')
    {
        if (!table_has_column('order_events', 'deleted_at')) return '';
        $p = $alias !== '' ? ('`' . str_replace('`', '', $alias) . '`.') : '';
        return $p . '`deleted_at` IS NULL';
    }
}

/**
 * 编辑一条进度节点（标题 / 备注 / 阶段 / 客户可见）。
 * 节点已经发给过客户，改的是「现在的说法」——原内容不在这里留底，
 * 但谁改的会进 admin_log 与 admin_id 字段。
 *
 * @param array $d 允许的键：stage / title_en / title_zh / note / visible
 * @return bool 是否有字段被更新
 */
if (!function_exists('update_order_event')) {
    function update_order_event($eventId, array $d)
    {
        $eventId = (int)$eventId;
        if ($eventId <= 0) return false;

        $sets = [];
        $args = [];
        if (isset($d['stage'])) {
            $allowed = ['pending_payment','paid','filed','examining','published','opposed','registered','refused','withdrawn','refunded','note'];
            if (in_array($d['stage'], $allowed, true)) { $sets[] = '`stage` = ?'; $args[] = $d['stage']; }
        }
        foreach (['title_en', 'title_zh', 'note'] as $k) {
            if (array_key_exists($k, $d)) { $sets[] = '`' . $k . '` = ?'; $args[] = (string)$d[$k]; }
        }
        if (isset($d['visible'])) { $sets[] = '`visible` = ?'; $args[] = $d['visible'] ? 1 : 0; }
        if (!$sets) return false;

        // 记下最后编辑人（列是 003 加的，没跑就跳过）
        if (table_has_column('order_events', 'admin_id')) {
            $sets[] = '`admin_id` = ?';
            $args[] = admin_id();
        }
        $args[] = $eventId;
        db_exec('UPDATE `order_events` SET ' . implode(', ', $sets) . ' WHERE id = ?', $args);
        return true;
    }
}

/**
 * 删除一条进度节点：软删优先（003 已跑），没跑迁移就物理删。
 * 软删时挂在该节点上的附件一并「跟着隐藏」——客户的 account.php 只列
 * 存活节点下的附件，孤儿附件（event 没了）本来就不显示，逻辑天然自洽。
 */
if (!function_exists('delete_order_event')) {
    function delete_order_event($eventId)
    {
        $eventId = (int)$eventId;
        if (table_has_column('order_events', 'deleted_at')) {
            db_exec('UPDATE `order_events` SET `deleted_at` = NOW() WHERE id = ?', [$eventId]);
        } else {
            db_exec('DELETE FROM `order_events` WHERE id = ?', [$eventId]);
        }
        return true;
    }
}

if (!function_exists('restore_order_event')) {
    function restore_order_event($eventId)
    {
        if (!table_has_column('order_events', 'deleted_at')) return false;
        db_exec('UPDATE `order_events` SET `deleted_at` = NULL WHERE id = ?', [(int)$eventId]);
        return true;
    }
}

/**
 * 后台手工建单（线下 / 邮件 / 电话来的单子）
 *
 * 为什么需要：有些客户从头到尾只跟我们邮件往来，让人家再去官网申请一遍报价
 * 既不现实也没必要。我们在后台直接建单，客户只需要付款。
 *
 * 与 create_order_from_enquiry() 的区别：
 *   - 不依赖 enquiries / quotes，四项要素和金额全部由管理员直接填；
 *   - 客户可以现选、也可以用邮箱现建（upsert_client 保证邮箱唯一）；
 *   - 可以一步到位登记为「已收款」（比如客户已经银行转账了）。
 *
 * @param array $d  至少要有 service_type 与 total（或三项金额）
 * @return int order id
 */
if (!function_exists('create_order_manual')) {
    function create_order_manual(array $d)
    {
        // 1) 客户：给了 client_id 就用，否则按邮箱取/建
        $clientId = (int)($d['client_id'] ?? 0);
        if (!$clientId) {
            $email = strtolower(trim((string)($d['email'] ?? '')));
            if ($email === '') throw new Exception('client email required');
            $clientId = upsert_client(
                $email,
                (string)($d['name'] ?? ''),
                (string)($d['company'] ?? ''),
                (string)($d['country'] ?? ''),
                (string)($d['phone'] ?? ''),
                !empty($d['lang']) ? $d['lang'] : null
            );
        }

        $official = (float)($d['official_fee'] ?? 0);
        $service  = (float)($d['service_fee'] ?? 0);
        $trans    = (float)($d['translation_fee'] ?? 0);
        $total    = isset($d['total']) && $d['total'] !== '' ? (float)$d['total'] : ($official + $service + $trans);
        $cur      = !empty($d['currency']) ? $d['currency'] : 'USD';

        $ref = next_ref('orders', 'ORD');

        $cols = ['ref', 'client_id', 'mark_name', 'mark_type',
                 'registrant_name', 'registrant_addr', 'registrant_type',
                 'classes_json', 'official_fee', 'service_fee', 'translation_fee',
                 'total', 'currency'];
        $vals = [
            $ref, $clientId,
            (string)($d['mark_name'] ?? ''),
            in_array(($d['mark_type'] ?? 'word'), ['word','device','combined','sound','other'], true) ? $d['mark_type'] : 'word',
            (string)($d['registrant_name'] ?? ''),
            (string)($d['registrant_addr'] ?? ''),
            in_array(($d['registrant_type'] ?? 'company'), ['individual','company','other'], true) ? $d['registrant_type'] : 'company',
            isset($d['classes_json']) ? $d['classes_json'] : null,
            $official, $service, $trans, $total, $cur,
        ];

        // 平滑迁移：这两列是后加的，线上库没跑迁移就别写，否则 Unknown column
        if (table_has_column('orders', 'service_type')) {
            $cols[] = 'service_type';
            $vals[] = (string)($d['service_type'] ?? 'registration');
        }
        if (table_has_column('orders', 'existing_reg_no')) {
            $cols[] = 'existing_reg_no';
            $vals[] = mb_substr((string)($d['existing_reg_no'] ?? ''), 0, 40);
        }

        /* 撞号重试（2026-09-30）：与 create_order_from_enquiry 同一套兜底。
         * $vals[0] 是 ref，撞 UNIQUE 就换号重来，最多 4 次。 */
        $orderId = 0;
        for ($attempt = 0; $attempt < 4; $attempt++) {
            try {
                $orderId = (int)db_exec(
                    'INSERT INTO orders (`' . implode('`,`', $cols) . '`,status)'
                    . ' VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ',"pending_payment")',
                    $vals
                );
                break;
            } catch (PDOException $e) {
                if (!is_duplicate_key($e) || $attempt === 3) throw $e;
                $vals[0] = next_ref('orders', 'ORD');
                app_log('create_order_manual: ref collision, retrying with ' . $vals[0], 'WARN');
            }
        }

        add_order_event($orderId, 'pending_payment',
            'Order created — awaiting payment', '订单已建立，待付款', '', 1);

        return $orderId;
    }
}

/**
 * 作废订单。
 *
 * 不做物理删除：订单是法律业务的凭证，删了就再也说不清。
 * 这里把状态置为 withdrawn（对外显示「已作废」），并记下原因与时间。
 */
if (!function_exists('void_order')) {
    function void_order($orderId, $reason = '')
    {
        $orderId = (int)$orderId;
        db_exec("UPDATE orders SET status='withdrawn' WHERE id=?", [$orderId]);
        if (table_has_column('orders', 'voided_at')) {
            db_exec('UPDATE `orders` SET `voided_at` = NOW(), `void_reason` = ? WHERE id = ?', [(string)$reason, $orderId]);
        }
        add_order_event($orderId, 'withdrawn', 'Order voided', '订单已作废', (string)$reason, 0);
        return true;
    }
}

if (!function_exists('restore_voided_order')) {
    /** Restore a voided order using its last recorded business stage. */
    function restore_voided_order($orderId)
    {
        $orderId = (int)$orderId;
        if (!table_has_column('orders', 'voided_at')) return false;
        $order = db_one('SELECT * FROM `orders` WHERE id=?', [$orderId]);
        if (!$order || empty($order['voided_at'])) return false;

        $deleted = table_has_column('order_events', 'deleted_at') ? ' AND deleted_at IS NULL' : '';
        $previous = db_val(
            "SELECT stage FROM `order_events` WHERE order_id=? AND stage NOT IN ('withdrawn','note') AND created_at<=? $deleted ORDER BY id DESC LIMIT 1",
            [$orderId, $order['voided_at']], ''
        );
        $allowed = ['pending_payment','paid','filed','examining','published','opposed','registered','refused','refunded'];
        if (!in_array($previous, $allowed, true)) {
            $previous = ((float)$order['total'] > 0 && (float)$order['paid_amount'] >= (float)$order['total'] - 0.001)
                ? 'paid' : 'pending_payment';
        }
        db_exec('UPDATE `orders` SET status=?, voided_at=NULL, void_reason=NULL WHERE id=?', [$previous, $orderId]);
        add_order_event($orderId, $previous, 'Order restored', '订单已恢复', '', 0);
        return $previous;
    }
}

/**
 * 客户上传的电汇回单表（2026-10-15）。
 * 客户汇完款把银行回单传上来 → 行状态 pending → 后台核对确认（confirm_receipt）
 * 时调 apply_payment 入账并触发「已收到款」邮件；驳回则置 rejected。
 * 幂等建表，配合平滑迁移：线上库没有这张表也不会让任何页面 500。
 */
if (!function_exists('ensure_receipts_table')) {
    function ensure_receipts_table()
    {
        try {
            db_exec("CREATE TABLE IF NOT EXISTS `receipts` (
                `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_id`        INT UNSIGNED NOT NULL,
                `doc_id`          INT UNSIGNED NOT NULL,
                `client_id`       INT UNSIGNED NOT NULL,
                `currency`        VARCHAR(8) NOT NULL DEFAULT '',
                `amount_declared` DECIMAL(12,2) NOT NULL DEFAULT 0,
                `status`          ENUM('pending','confirmed','rejected') NOT NULL DEFAULT 'pending',
                `note`            TEXT NULL,
                `admin_id`        INT UNSIGNED DEFAULT NULL,
                `confirmed_at`    DATETIME DEFAULT NULL,
                `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `ix_receipt_order` (`order_id`),
                KEY `ix_receipt_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return true;
        } catch (Throwable $e) {
            app_log('ensure_receipts_table failed: ' . $e->getMessage(), 'ERROR');
            return false;
        }
    }
}

/**
 * 服务合同表（2026-10-15）。
 * 一单一合同：客户在 contract.php 手写签名 → 签名 PNG 存 documents（kind='other'）
 * → 本表记 signed + signed_name + signature_doc_id。幂等建表，线上库没有也不会 500。
 */
if (!function_exists('ensure_contracts_table')) {
    function ensure_contracts_table()
    {
        try {
            db_exec("CREATE TABLE IF NOT EXISTS `contracts` (
                `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_id`         INT UNSIGNED NOT NULL,
                `client_id`        INT UNSIGNED NOT NULL,
                `status`           ENUM('draft','signed') NOT NULL DEFAULT 'draft',
                `signed_name`      VARCHAR(190) NOT NULL DEFAULT '',
                `signature_doc_id` INT UNSIGNED DEFAULT NULL,
                `signed_at`        DATETIME DEFAULT NULL,
                `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_contract_order` (`order_id`),
                KEY `ix_contract_client` (`client_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return true;
        } catch (Throwable $e) {
            app_log('ensure_contracts_table failed: ' . $e->getMessage(), 'ERROR');
            return false;
        }
    }
}

/**
 * 记录一笔付款（幂等 + 防并发双入账）
 * - 同一 provider_ref 已 completed 则忽略（return capture 与 webhook 双腿并发的第一道防线）
 * - 同订单同通道存在未完成行（如 paypal_create 预插的 created 行）则复用更新，不再新插 —— 消除"一单两条流水"
 * - GET_LOCK 把同订单的入账串行化，防两腿真正并发时同时通过查重
 *
 * 2026-09-30：整段入账过程包进事务。
 * 「写 payments 流水」和「把 orders.paid_amount 加上去」必须同生共死：
 * 原来两步之间进程被杀 / MySQL 断开，就会出现「有流水但订单金额没变」的
 * 幽灵账。现在任一步失败整体回滚，前端看到的是失败而不是半截账。
 *
 * 2026-09-29：入账成功后统一给客户发「付款已收到」邮件。
 * 之前只有 PayPal 两处在发，后台登记的银行转账客户完全不知情；
 * 收口到这里后四条路径（回跳 / webhook / 后台 mark_paid / 手工建单）都覆盖到，
 * 且因为只有返回 true（= 真的新建了入账）才发，PayPal「回跳 + webhook」双腿不会重复发。
 *
 * @param bool $notifyClient 是否给客户发「付款已收到」邮件。
 *                           前台 PayPal 两条路径恒为 true；后台登记银行转账时由
 *                           ad_wants_notify()（页面上的「同时发邮件通知客户」勾选）决定。
 * @return bool 是否为新付款
 */
if (!function_exists('apply_payment')) {
    function apply_payment($orderId, $amount, $method = 'paypal', $providerRef = '', $rawJson = '', $notifyClient = true)
    {
        $orderId = (int)$orderId;
        $lock    = 'apply_pay_' . $orderId;
        $isNew   = false;
        db_val('SELECT GET_LOCK(?, 5)', [$lock]);          // 最多等 5s；超时也继续（降级为查重防线）
        try {
            // 作废的订单不入账——覆盖三条路径：PayPal 回跳 / webhook / 后台登记银行收款。
            // 客户在作废前已批准的付款不会在这里被扣（paypal_return 也拦了一层）。
            if (table_has_column('orders', 'voided_at')) {
                $vd = db_one('SELECT voided_at FROM orders WHERE id=?', [$orderId]);
                if ($vd && !empty($vd['voided_at'])) {
                    app_log('apply_payment rejected: order #' . $orderId . ' is voided', 'WARN');
                    return false;
                }
            }
            if ($providerRef !== '') {
                $exists = db_val('SELECT id FROM payments WHERE provider_ref=? AND status="completed"', [$providerRef], null);
                if ($exists) return false;
            }

            /* 手工登记的防重窗口（2026-09-30）
             * bank_transfer 这类人工入账没有外部流水号，provider_ref 是我们用
             * date('YmdHis') 现编的 —— 跨一秒的两次提交就是两个号，上面那条
             * provider_ref 幂等直接失效，同一笔到账会被记两次。
             * 表单侧已经用一次性令牌（idem_field / idem_check）挡了一层，
             * 这里是最后一道兜底：同订单 + 同金额 + 5 分钟内已入账 → 判定重复提交。
             * 只作用于 provider_ref 为空或以 MANUAL- 开头的登记，PayPal 不受影响
             * （它有自己的 capture id，靠上面的幂等足够）。
             * 5 分钟内真的分两笔等额转账的概率极低；真撞了，后台改金额即可，
             * 比多记一笔钱好排查得多。 */
            if ($providerRef === '' || strncmp($providerRef, 'MANUAL-', 7) === 0) {
                $dupe = (int)db_val(
                    'SELECT id FROM payments
                     WHERE order_id = ? AND status = "completed"
                       AND ABS(amount - ?) < 0.001
                       AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                     ORDER BY id DESC LIMIT 1',
                    [$orderId, (float)$amount], 0
                );
                if ($dupe) {
                    app_log('apply_payment rejected: duplicate manual entry for order #' . $orderId
                        . ' amount=' . $amount . ' (existing payment #' . $dupe . ')', 'WARN');
                    return false;
                }
            }
            /* 从这里开始进事务：流水 + 订单金额 + 状态 + 事件，要么全成要么全回滚（2026-09-30） */
            $pdo = db();
            $pdo->beginTransaction();
            try {
                // 复用同订单同通道的未完成行（paypal_create 预插的 created 行）
                $reuse = (int)db_val('SELECT id FROM payments WHERE order_id=? AND method=? AND status<>"completed" ORDER BY id ASC LIMIT 1',
                    [$orderId, $method], 0);
                if ($reuse) {
                    db_exec('UPDATE payments SET amount=?, provider=?, provider_ref=?, provider_json=?, status="completed", paid_at=NOW() WHERE id=?',
                        [(float)$amount, $method, $providerRef, $rawJson, $reuse]);
                } else {
                    /* ⚠️ 七个 ? 要配七个参数（2026-09-29 修）
                     * 这里原先少传了一个 $method：SQL 里 method 和 provider 是两列，
                     * 却只给了一个值 → PDO 报 HY093，整笔入账失败。
                     * PayPal 之所以一直没暴露，是因为 paypal_create 预插了 created 行，
                     * 回跳/webhook 都走上面的 UPDATE 复用分支；只有后台登记银行转账会
                     * 走到这条 INSERT，所以「银行转账收款」一直是 100% 失败的。 */
                    db_exec('INSERT INTO payments (order_id,amount,currency,method,provider,provider_ref,provider_json,status,paid_at)
                             SELECT ?,?,currency,?,?,?,?, "completed", NOW() FROM orders WHERE id=?',
                        [$orderId, (float)$amount, $method, $method, $providerRef, $rawJson, $orderId]);
                }

                db_exec('UPDATE orders SET paid_amount = paid_amount + ? WHERE id=?', [(float)$amount, $orderId]);
                $o = db_one('SELECT * FROM orders WHERE id=?', [$orderId]);
                if ($o && $o['status'] === 'pending_payment' && (float)$o['paid_amount'] >= (float)$o['total'] - 0.001) {
                    db_exec("UPDATE orders SET status='paid' WHERE id=?", [$orderId]);
                    add_order_event($orderId, 'paid', 'Payment received in full', '已收到全额付款', '', 1);
                }
                /* 多收护栏（2026-09-30，只记日志不改行为）
                 * 四个入账入口共用这里，一旦出现「已收 > 应收」——重复入账、
                 * 金额填错、PayPal 分账异常——都能在日志里第一时间看到，
                 * 不用等月底对账才发现。这里只观察不阻断：多收有时是对的（客户补差价）。 */
                if ($o && (float)$o['paid_amount'] > (float)$o['total'] + 0.01) {
                    app_log('apply_payment: order #' . $orderId . ' is overpaid — paid='
                        . $o['paid_amount'] . ' total=' . $o['total'] . ' method=' . $method, 'WARN');
                }
                $pdo->commit();
                $isNew = true;
            } catch (Throwable $e) {
                try { $pdo->rollBack(); } catch (Throwable $e2) { /* 已经回滚过了 */ }
                app_log('apply_payment failed for order #' . $orderId . ': ' . $e->getMessage(), 'ERROR');
                return false;
            }
        } finally {
            db_val('SELECT RELEASE_LOCK(?)', [$lock]);
        }

        /* 发信放在锁外：SMTP 可能耗几秒，不该让它占着入账锁
           $notifyClient = false 时只入账、不发信（后台静默登记用） */
        if ($isNew && $notifyClient) {
            try {
                $po = db_one('SELECT * FROM orders WHERE id=?', [$orderId]);
                $pc = $po ? db_one('SELECT * FROM clients WHERE id=?', [$po['client_id']]) : null;
                if ($po && $pc) {
                    mail_payment_received($po, $pc, $amount);
                }
            } catch (Throwable $e) {
                // 邮件失败不能影响已入账的结果，只记日志（队列里还有一条可重投）
                app_log('apply_payment: mail_payment_received failed for order #' . $orderId . ': ' . $e->getMessage(), 'ERROR');
            }
        }
        return $isNew;
    }
}

/**
 * 登记退款。
 *
 * 关键点：
 *   - 可以退多次（分批退），refund_amount 累加，退超了自动截断到「还剩多少」；
 *   - 退满 → status = refunded，只退一部分 → status = partially_refunded；
 *     后者是 003 迁移才加的枚举值，线上库没跑迁移时退回用 refunded（宁可状态粗一点，不能报错）；
 *   - 同步把 orders.paid_amount 减回去，并用 GREATEST(0, ...) 兜底不减成负数；
 *   - 写一条订单事件，客户在 account.php 里能看到这笔退款。
 *
 * ★ 并发安全（2026-09-30 修复）
 *   原实现是「先读后写」的裸奔逻辑：后台连点两下、或两个管理员同时提交同一笔退款，
 *   两边读到的 refund_amount 都是旧值，各自算出同一个 remain，于是同一笔钱被退两次、
 *   orders.paid_amount 也被减两次 —— 账上凭空多出一笔退款。
 *   现在改成：GET_LOCK 按 payment 串行化 + 事务包裹 + SELECT ... FOR UPDATE 锁行，
 *   「读余额 → 写退款 → 回退订单金额 → 写事件」成为一个不可分割的整体。
 *   并且只接受 status 为 completed / partially_refunded 的流水，
 *   created（PayPal 预插占位）/ pending / failed（根本没收到钱）/ refunded（已退满）一律拒绝。
 *
 * @return array ['ok'=>bool, 'amount'=>float, 'full'=>bool, 'error'=>string]
 */
if (!function_exists('refund_payment')) {
    function refund_payment($paymentId, $amount, $note = '')
    {
        $paymentId = (int)$paymentId;
        if ($paymentId <= 0) return ['ok' => false, 'error' => 'payment not found'];
        $amount = round((float)$amount, 2);
        if ($amount <= 0)    return ['ok' => false, 'error' => 'amount must be > 0'];

        // 列探测放在事务外：table_has_column 要查 information_schema，没必要占着事务
        $hasRefund  = table_has_column('payments', 'refund_amount');
        $hasRefAt   = table_has_column('payments', 'refunded_at');
        $hasRefNote = table_has_column('payments', 'refund_note');
        $hasAdmin   = table_has_column('payments', 'admin_id');

        $lock = 'refund_pay_' . $paymentId;
        db_val('SELECT GET_LOCK(?, 10)', [$lock]);     // 最多等 10s；超时也继续（降级为行锁 + 状态校验）

        try {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                // 锁内重读：锁开始前读过的任何数据都已不可信
                $p = db_one('SELECT * FROM `payments` WHERE id = ? FOR UPDATE', [$paymentId]);
                if (!$p) {
                    $pdo->rollBack();
                    return ['ok' => false, 'error' => 'payment not found'];
                }
                if ($p['status'] === 'refunded') {
                    $pdo->rollBack();
                    return ['ok' => false, 'error' => 'already fully refunded'];
                }
                // created = PayPal 下单时预插的占位行；pending/failed = 钱没真正到账。
                // 这些都不能退，退了账上就是负数。
                if (!in_array((string)$p['status'], ['completed', 'partially_refunded'], true)) {
                    $pdo->rollBack();
                    return ['ok' => false, 'error' => 'payment is not settled (status=' . $p['status'] . ')'];
                }

                $already = $hasRefund ? round((float)($p['refund_amount'] ?? 0), 2) : 0;
                $remain  = round((float)$p['amount'] - $already, 2);
                if ($remain <= 0) {
                    $pdo->rollBack();
                    return ['ok' => false, 'error' => 'already fully refunded'];
                }
                if ($amount > $remain) $amount = $remain;

                $newTotal = round($already + $amount, 2);
                $full     = $newTotal >= round((float)$p['amount'], 2) - 0.001;
                // 003 没跑 → 没有 refund_amount 列，也没有 partially_refunded 枚举值，统一用 refunded
                $newStatus = $full ? 'refunded' : ($hasRefund ? 'partially_refunded' : 'refunded');

                $sets = ['`status` = ?'];
                $args = [$newStatus];
                if ($hasRefund)  { $sets[] = '`refund_amount` = ?'; $args[] = $newTotal; }
                if ($hasRefAt)   { $sets[] = '`refunded_at` = NOW()'; }
                if ($hasRefNote) { $sets[] = '`refund_note` = ?';    $args[] = (string)$note; }
                if ($hasAdmin)   { $sets[] = '`admin_id` = ?';       $args[] = admin_id(); }
                $args[] = $paymentId;
                db_exec('UPDATE `payments` SET ' . implode(', ', $sets) . ' WHERE id = ?', $args);

                // 回退已收金额（不减成负数）
                db_exec('UPDATE orders SET paid_amount = GREATEST(0, paid_amount - ?) WHERE id = ?', [$amount, (int)$p['order_id']]);

                // 全额退掉后如果订单还停在 paid，退回待付款
                $o = db_one('SELECT * FROM orders WHERE id = ?', [(int)$p['order_id']]);
                if ($o && $o['status'] === 'paid' && (float)$o['paid_amount'] < (float)$o['total'] - 0.001) {
                    db_exec("UPDATE orders SET status='pending_payment' WHERE id=?", [(int)$o['id']]);
                }

                $amtTxt = money($amount, $p['currency']);
                add_order_event((int)$p['order_id'], 'refunded',
                    ($full ? 'Refunded in full — ' : 'Partially refunded — ') . $amtTxt,
                    ($full ? '已全额退款 ' : '已部分退款 ') . $amtTxt,
                    (string)$note, 1);

                $pdo->commit();
            } catch (Throwable $e) {
                try { $pdo->rollBack(); } catch (Throwable $e2) { /* 已经回滚过了 */ }
                app_log('refund_payment failed for payment #' . $paymentId . ': ' . $e->getMessage(), 'ERROR');
                return ['ok' => false, 'error' => 'internal error, nothing was refunded'];
            }
        } finally {
            db_val('SELECT RELEASE_LOCK(?)', [$lock]);
        }

        return ['ok' => true, 'amount' => $amount, 'full' => $full, 'error' => ''];
    }
}

/** 报价合计 */
if (!function_exists('quote_total')) {
    function quote_total($official, $service, $translation, $extras = [])
    {
        $t = (float)$official + (float)$service + (float)$translation;
        if (is_array($extras)) {
            foreach ($extras as $e) $t += (float)($e['amount'] ?? 0);
        }
        return $t;
    }
}

/**
 * 商标类型的人类可读标签：word/device/… → Word mark / 文字商标。
 *
 * 库里存的是枚举值（word / device / combined / sound / other），直接甩给客户
 * 会出现「MYCNTM word」这种读法 —— 客户会以为商标本身叫 "MYCNTM Word"。
 * 客户页必须给它一个独立形态（胶囊标签）+ 可读文案（2026-10-18）。
 */
if (!function_exists('mark_type_label')) {
    function mark_type_label($t, $code = 'en')
    {
        $zh  = ($code === 'zh');
        $map = [
            'word'     => $zh ? '文字商标' : 'Word mark',
            'device'   => $zh ? '图形商标' : 'Device mark',
            'combined' => $zh ? '组合商标' : 'Combined mark',
            'sound'    => $zh ? '声音商标' : 'Sound mark',
            'other'    => $zh ? '其他类型' : 'Other mark',
        ];
        $t = strtolower(trim((string)$t));
        if ($t === '') return '';
        return isset($map[$t]) ? $map[$t] : $t;
    }
}
