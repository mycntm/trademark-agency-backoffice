<?php
/**
 * core.php —— 引导、数据库、会话、安全、工具函数
 * 依赖：PHP 7.0+ / PDO_MySQL
 */

/**
 * 会话有效期（秒）。
 * 单独放在文件最前面，因为 session_start() 在 cfg() 定义之前就要用到它。
 * 想和 magic link 同步改的话，把这里和 config.php 的 security.token_ttl_min 一起调。
 * 2026-10-15：与 magic link 一起从 24 小时放宽到 15 天，让客户登录后在客户中心
 * 能跨多天继续处理下单 / 付款 / 上传回单，无需反复重新登录。
 */
if (!function_exists('token_session_lifetime')) {
    function token_session_lifetime()
    {
        return 15 * 24 * 60 * 60;   // 15 天，与 magic link 对齐
    }
}

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    /* 会话有效期 15 天，与 magic link 对齐（2026-10-15 由 24 小时放宽）
     * PHP 默认 session.gc_maxlifetime 只有 1440 秒（24 分钟）——
     * 客户点链接登录成功后不到半小时就被登出，又得回头重新要链接，
     * 这才是「每半个钟头就要重来一次」的根因，光放宽 token TTL 治不了。
     * 副作用：后台管理员会话同样变成 15 天无操作才登出；
     * 若要更严，可在 admin 侧单独加空闲超时（本项目暂未做）。 */
    ini_set('session.gc_maxlifetime', (string)token_session_lifetime());
    session_set_cookie_params(token_session_lifetime(), '/', '',
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'), true);
    session_name('CNTMSID');
    session_start();
}

/* ============================ 配置 ============================ */

if (!function_exists('cfg')) {
    function cfg($key = null, $default = null)
    {
        static $c = null;
        if ($c === null) {
            $file = __DIR__ . '/../config.php';
            if (!is_file($file)) {
                $file = __DIR__ . '/../config.example.php';
            }
            $c = require $file;
        }
        if ($key === null) return $c;
        // 支持 'db.host' 点式访问
        if (strpos($key, '.') !== false) {
            $v = $c;
            foreach (explode('.', $key) as $seg) {
                if (!is_array($v) || !array_key_exists($seg, $v)) return $default;
                $v = $v[$seg];
            }
            return $v;
        }
        return array_key_exists($key, $c) ? $c[$key] : $default;
    }
}

date_default_timezone_set(cfg('timezone', 'Asia/Shanghai'));

if (cfg('debug')) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
    ini_set('display_errors', '0');
}

/* ============================ 数据库 ============================ */

/**
 * MySQL 会话时区偏移量，形如 '+08:00'（由 cfg('timezone') 推导）
 *
 * 为什么不用 'Asia/Shanghai' 这种命名时区：它需要 MySQL 装载了时区表
 * （mysql.time_zone_name），相当多宝塔装的 MySQL 没有，SET time_zone 会直接报错。
 * 固定偏移不依赖任何系统表，中国也无夏令时，是最稳的做法。
 */
if (!function_exists('sql_tz_offset')) {
    function sql_tz_offset()
    {
        try {
            $tz  = new DateTimeZone(cfg('timezone', 'Asia/Shanghai'));
            $off = $tz->getOffset(new DateTime('now', new DateTimeZone('UTC')));
            $abs = abs($off);
            return sprintf('%s%02d:%02d', $off < 0 ? '-' : '+', intdiv($abs, 3600), intdiv($abs % 3600, 60));
        } catch (Throwable $e) {
            return '+08:00';
        }
    }
}

if (!function_exists('db')) {
    function db()
    {
        static $pdo = null;
        if ($pdo === null) {
            $d = cfg('db');
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $d['host'], $d['port'], $d['name'], $d['charset']);
            $pdo = new PDO($dsn, $d['user'], $d['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            /* 关键：把 MySQL 会话时区对齐到 PHP 时区（2026-09-29）
             * 全站 18 处用 NOW() 写 created_at / paid_at / sent_at 等，
             * 而页面又是直接把库里的 datetime 原样打印出来的。
             * 服务器 MySQL 若跑在 UTC（宝塔默认是 UTC 的情况不少见），
             * 所有时间都会比实际早 8 小时：客户看到「付款时间 08:00」其实是下午 4 点。
             * 这一行之后，NOW() 与 PHP 的 date() 就在同一时区了。 */
            try {
                $pdo->exec("SET time_zone = '" . sql_tz_offset() . "'");
            } catch (Throwable $e) {
                app_log('db: SET time_zone failed: ' . $e->getMessage(), 'WARN');
            }
        }
        return $pdo;
    }
}

/** 读一条 */
if (!function_exists('db_one')) {
    function db_one($sql, $params = [])
    {
        $st = db()->prepare($sql);
        $st->execute($params);
        $r = $st->fetch();
        return $r === false ? null : $r;
    }
}

/** 读多条 */
if (!function_exists('db_all')) {
    function db_all($sql, $params = [])
    {
        $st = db()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }
}

/** 写操作，返回 lastInsertId */
if (!function_exists('db_exec')) {
    function db_exec($sql, $params = [])
    {
        $st = db()->prepare($sql);
        $st->execute($params);
        return db()->lastInsertId();
    }
}

/** 取单个标量 */
if (!function_exists('db_val')) {
    function db_val($sql, $params = [], $default = null)
    {
        $r = db_one($sql, $params);
        if (!$r) return $default;
        $v = reset($r);
        return $v === null ? $default : $v;
    }
}

/**
 * 判断某表是否已有某列（用于平滑迁移：新字段上线前后都能正常写入）
 */
if (!function_exists('table_has_column')) {
    function table_has_column($table, $column)
    {
        static $cache = [];
        $ck = $table . '.' . $column;
        if (isset($cache[$ck])) return $cache[$ck];
        try {
            $n = (int)db_val(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column], 0
            );
        } catch (Exception $e) {
            $n = 0;
        }
        $cache[$ck] = ($n > 0);
        return $cache[$ck];
    }
}

/* ============================ 设置表 ============================ */

if (!function_exists('setting')) {
    function setting($k, $default = null)
    {
        static $cache = [];
        if (array_key_exists($k, $cache)) return $cache[$k];
        try {
            $v = db_val('SELECT `v` FROM settings WHERE `k` = ?', [$k], null);
        } catch (Exception $e) {
            $v = null;
        }
        $cache[$k] = ($v === null) ? $default : $v;
        return $cache[$k];
    }
}

/* ============================ 输入与输出 ============================ */

if (!function_exists('post')) {
    function post($key, $default = '')
    {
        return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
    }
}
if (!function_exists('get')) {
    function get($key, $default = '')
    {
        return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
    }
}
if (!function_exists('h')) {
    function h($s)
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
if (!function_exists('json_out')) {
    function json_out($arr, $code = 200)
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
if (!function_exists('abort404')) {
    function abort404($msg = 'Not found')
    {
        http_response_code(404);
        echo '<!doctype html><meta charset="utf-8"><h1>404</h1><p>' . h($msg) . '</p>';
        exit;
    }
}
if (!function_exists('redirect')) {
    function redirect($url)
    {
        header('Location: ' . $url);
        exit;
    }
}
if (!function_exists('client_ip')) {
    function client_ip()
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) {
                $v = explode(',', $_SERVER[$k]);
                return trim($v[0]);
            }
        }
        return '';
    }
}
if (!function_exists('user_agent')) {
    function user_agent()
    {
        return isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : '';
    }
}
if (!function_exists('is_post')) {
    function is_post()
    {
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    }
}
if (!function_exists('site_url')) {
    function site_url($path = '')
    {
        return rtrim(cfg('site_url'), '/') . '/' . ltrim($path, '/');
    }
}

/* ============================ 安全 ============================ */

/** CSRF：生成 token（写入 session） */
if (!function_exists('csrf_token')) {
    function csrf_token()
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return hash_hmac('sha256', $_SESSION['csrf'] . '|' . (string)session_id(), cfg('security.csrf_secret'));
    }
}
/** CSRF：输出隐藏域 */
if (!function_exists('csrf_field')) {
    function csrf_field()
    {
        return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
    }
}
/** CSRF：校验（hash_equals 防时序攻击） */
if (!function_exists('csrf_check')) {
    function csrf_check($token = null)
    {
        if ($token === null) $token = (string)($_POST['csrf'] ?? '');
        $expected = csrf_token();
        return ($token !== '' && hash_equals($expected, $token));
    }
}
/** CSRF 校验失败直接中断（JSON 接口用） */
if (!function_exists('csrf_guard')) {
    function csrf_guard()
    {
        if (!csrf_check()) {
            json_out(['ok' => false, 'message' => 'Session expired. Please refresh the page and try again.'], 419);
        }
    }
}

/* --------------------------------------------------------------
 * 一次性表单令牌（2026-09-30 新增）—— 专治「刷新 / 后退再提交」造成的重复入账
 *
 * 背景：后台登记银行转账时 provider_ref 用的是 'MANUAL-' . date('YmdHis')（秒级）。
 * 同一秒内连点两下会被 apply_payment 的 provider_ref 幂等挡住，
 * 但间隔 1 秒以上的两次提交就是两个不同的 provider_ref —— 一笔到账记两次，
 * 客户账上凭空多出一笔钱，对账时还很难发现。
 *
 * CSRF 令牌在同一会话内是固定值，挡不住重复提交。所以这里另发一个
 * 「只能用一次」的令牌：页面渲染时生成并存进 session，提交时校验并立即销毁。
 *
 * 用法：
 *   表单里   <?php echo idem_field('mark_paid_' . $id); ?>
 *   处理时   if (!idem_check('mark_paid_' . $id)) { $err = '...重复提交...'; }
 * -------------------------------------------------------------- */
if (!function_exists('idem_field')) {
    function idem_field($slot)
    {
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
        $t = bin2hex(random_bytes(16));
        if (!isset($_SESSION['idem']) || !is_array($_SESSION['idem'])) $_SESSION['idem'] = [];
        /* 同一个 slot 保留最近 5 个而不是只留一个：管理员常开好几个标签页
         * 对着同一张订单操作，只留一个的话后开的页面会把先开那个的令牌顶掉，
         * 前者一提交就被判成「重复提交」。每个令牌依然只能用一次。 */
        if (!isset($_SESSION['idem'][$slot]) || !is_array($_SESSION['idem'][$slot])) $_SESSION['idem'][$slot] = [];
        $_SESSION['idem'][$slot][] = $t;
        if (count($_SESSION['idem'][$slot]) > 5) array_shift($_SESSION['idem'][$slot]);
        return '<input type="hidden" name="idem" value="' . h($t) . '">';
    }
}
if (!function_exists('idem_check')) {
    function idem_check($slot)
    {
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
        $got  = (string)($_POST['idem'] ?? '');
        $list = (isset($_SESSION['idem'][$slot]) && is_array($_SESSION['idem'][$slot]))
            ? $_SESSION['idem'][$slot] : [];
        if ($got === '' || !$list) return false;

        $ok = false;
        foreach ($list as $i => $t) {
            if (hash_equals((string)$t, $got)) { $ok = true; unset($list[$i]); break; }
        }
        // 命中的那个被消耗掉；没命中就把整个 slot 清空（不给反复重试的窗口，
        // 刷新页面重新拿一个令牌即可继续）
        $_SESSION['idem'][$slot] = $ok ? array_values($list) : [];
        return $ok;
    }
}
/** 同键的重复提交提示文案（中英随 lang()） */
if (!function_exists('idem_dup_message')) {
    function idem_dup_message()
    {
        return lang() === 'zh'
            ? '该表单已提交过，请勿重复提交。请刷新页面确认结果后再操作。'
            : 'This form has already been submitted. Please refresh the page and check the result before trying again.';
    }
}

/** 蜜罐：机器人会填隐藏字段 */
if (!function_exists('honeypot_tripped')) {
    function honeypot_tripped()
    {
        // 字段名与 contact-handler.php 保持一致的 'website'，另加 'company_url'
        foreach (['website', 'company_url', 'fax'] as $f) {
            if (!empty($_POST[$f])) return true;
        }
        return false;
    }
}

/** 频率限制：每 IP 每小时 N 次 */
if (!function_exists('rate_limit')) {
    function rate_limit($bucket, $limit = null)
    {
        if ($limit === null) {
            $map = cfg('security.rate_limit', []);
            $limit = isset($map[$bucket]) ? (int)$map[$bucket] : 10;
        }
        $ip = client_ip();
        try {
            db_exec('DELETE FROM rate_limits WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)');
            $n = (int)db_val('SELECT COUNT(*) FROM rate_limits WHERE bucket = ? AND ip = ?', [$bucket, $ip], 0);
            if ($n >= $limit) return false;
            db_exec('INSERT INTO rate_limits (bucket, ip) VALUES (?, ?)', [$bucket, $ip]);
            return true;
        } catch (Exception $e) {
            return true; // 表不存在时放行，避免阻断业务
        }
    }
}

/* ============================ 反垃圾 ============================ */
/*
 * 四道闸门，全部「静默」——判定为机器人时返回 ok，但不发信、不落库：
 *   1) 时间闸门：表单渲染时下发带签名的时间戳，提交太快（<min_seconds）必然是脚本；
 *   2) 链接闸门：正文链接条数超上限 = 发外链广告；
 *   3) 关键词闸门：命中黑名单词；
 *   4) 重复闸门：同一会话短时间重复提交同一内容（防误触与半自动刷屏）。
 *
 * 为什么是「静默」：一旦让机器人看到报错，它会立刻换 UA / 换内容重试；
 * 返回成功反而让它以为得手，不再骚扰。真实拦截记录一律写 app_log。
 */

if (!function_exists('spam_cfg')) {
    function spam_cfg($key, $default = null)
    {
        $v = cfg('security.spam.' . $key, null);
        return $v === null ? $default : $v;
    }
}

/** 表单里放的隐藏时间戳（带 HMAC 签名，机器人改不了） */
if (!function_exists('spam_field')) {
    function spam_field()
    {
        $secret = cfg('security.csrf_secret', 'cntm');
        $t      = (string)time();
        $sig    = substr(hash_hmac('sha256', $t, $secret), 0, 32);
        return '<input type="hidden" name="ts" value="' . h($t . '.' . $sig) . '">';
    }
}

/**
 * 校验时间闸门。返回 [ok, reason]
 *
 * 分「硬拦」与「软放行」两类，这是刻意的设计：
 *   硬拦  too-fast / bad-token / bad-signature / future —— 一定是脚本或篡改；
 *   软放  no-token / stale —— 可能是**真人拿着缓存的旧页面**在提交，
 *         例如部署前就打开着的标签页、浏览器后退恢复的表单、CDN 缓存的 HTML。
 *         这类只记日志不拦，否则会把真实客户静默吞掉，代价远大于漏放一个机器人。
 */
if (!function_exists('spam_time_ok')) {
    function spam_time_ok($raw)
    {
        $raw = trim((string)$raw);
        if ($raw === '' || strpos($raw, '.') === false) {
            return [true, 'no-token'];        // 软放行：老页面没有这个字段
        }
        list($t, $sig) = explode('.', $raw, 2);
        if (!ctype_digit($t)) return [false, 'bad-token'];
        $secret = cfg('security.csrf_secret', 'cntm');
        if (!hash_equals(substr(hash_hmac('sha256', $t, $secret), 0, 32), $sig)) {
            return [false, 'bad-signature'];
        }
        $min  = (int)spam_cfg('min_seconds', 3);
        $maxH = (int)spam_cfg('max_age_hours', 24);
        $age  = time() - (int)$t;
        if ($age < 0)               return [false, 'future'];
        if ($age < $min)            return [false, 'too-fast'];   // 秒提交 = 脚本
        if ($age > $maxH * 3600)    return [true,  'stale'];      // 软放行：页面放了很久
        return [true, ''];
    }
}

/**
 * 综合判定。入参：
 *   $text   正文（也把 name/company 等一起拼进来查关键词）
 *   $email  邮箱（仅用于日志）
 * 返回 ['ok'=>bool, 'reason'=>string]
 */
if (!function_exists('spam_scan')) {
    function spam_scan($text, $email = '')
    {
        if (!spam_cfg('enabled', true)) return ['ok' => true, 'reason' => ''];

        $text = (string)$text;

        /* ---- 2) 链接闸门 ---- */
        $maxLinks = (int)spam_cfg('max_links', 3);
        preg_match_all('~https?://|www\.|<a\s+href~i', $text, $m);
        if (count($m[0]) > $maxLinks) {
            return ['ok' => false, 'reason' => 'too-many-links(' . count($m[0]) . ')'];
        }

        /* ---- 3) 关键词闸门（关键词是 ASCII，用 strtolower/strpos 即可，不依赖 mbstring） ---- */
        $words = spam_cfg('keywords', []);
        if (is_array($words) && $words) {
            $low = strtolower($text);
            foreach ($words as $w) {
                $w = strtolower(trim((string)$w));
                if ($w !== '' && strpos($low, $w) !== false) {
                    return ['ok' => false, 'reason' => 'keyword(' . $w . ')'];
                }
            }
        }

        /* ---- 4) 重复闸门（会话级，30 分钟内同一内容只算一次） ---- */
        if (spam_seen($text)) {
            return ['ok' => false, 'reason' => 'duplicate'];
        }

        return ['ok' => true, 'reason' => ''];
    }
}

if (!function_exists('spam_key')) {
    function spam_key($text)
    {
        return md5(strtolower(trim(preg_replace('~\s+~', ' ', (string)$text))));
    }
}

/**
 * 是否已提交过同一内容（只读）。
 * 注意：只在**发送成功**后才调用 spam_mark_seen() 记录，
 * 否则一旦 SMTP 挂了，访客重试会被自己的去重记录静默吞掉。
 */
if (!function_exists('spam_seen')) {
    function spam_seen($text)
    {
        if (!isset($_SESSION['spam_seen']) || !is_array($_SESSION['spam_seen'])) return false;
        $now  = time();
        $hash = spam_key($text);
        foreach ($_SESSION['spam_seen'] as $k => $v) {
            if ($now - (int)$v > 1800) unset($_SESSION['spam_seen'][$k]);   // 顺手清理过期
        }
        return isset($_SESSION['spam_seen'][$hash]);
    }
}

if (!function_exists('spam_mark_seen')) {
    function spam_mark_seen($text)
    {
        if (!isset($_SESSION['spam_seen']) || !is_array($_SESSION['spam_seen'])) {
            $_SESSION['spam_seen'] = [];
        }
        $_SESSION['spam_seen'][spam_key($text)] = time();
    }
}

/**
 * 统一入口：先过时间闸门，再过内容闸门。
 * 命中时写 app_log（便于回看拦截了什么），不抛异常、不阻断调用方。
 */
if (!function_exists('spam_gate')) {
    function spam_gate($text, $email = '', $tsRaw = null)
    {
        if (!spam_cfg('enabled', true)) return ['ok' => true, 'reason' => ''];

        $ip = client_ip();

        if ($tsRaw !== null) {
            list($ok, $reason) = spam_time_ok($tsRaw);
            if ($reason !== '') {
                // 软放行（no-token / stale）只记 INFO；硬拦记 WARN
                app_log("spam [{$reason}] ip={$ip} email=" . (string)$email, $ok ? 'INFO' : 'WARN');
            }
            if (!$ok) return ['ok' => false, 'reason' => $reason];
        }

        $r = spam_scan($text, $email);
        if (!$r['ok']) {
            app_log("spam blocked [{$r['reason']}] ip={$ip} email=" . (string)$email, 'WARN');
        }
        return $r;
    }
}

/** 生成 URL 安全随机串 */
if (!function_exists('random_token')) {
    function random_token($bytes = 32)
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}

/* ============================ 编号生成 ============================ */

if (!function_exists('next_ref')) {
    /**
     * 生成当日流水号（ORD-20261001-0008）
     *
     * ★ 2026-09-30 重写为原子取号。原实现是「SELECT MAX+1 → 调用方 INSERT」，
     *   中间存在并发窗口：两个请求同时读到 0007、都算出 0008。
     *   ref 列上有 UNIQUE KEY，后插的那个会直接抛 PDOException ——
     *   客户看到 500 白页，而钱/单已经处在半完成状态，是最难排查的一类事故。
     *
     *   三道防线：
     *     1) 表名白名单：$table 是拼进 SQL 的，绝不接受任意字符串；
     *     2) GET_LOCK：同一时刻只允许一个请求算号，把并发窗口压到最低；
     *     3) 取「序号的数值最大」而不是「id 最大的那行」——
     *        万一 id 顺序与序号不一致（手工补录、数据修复），也不会算错。
     *   最后一道兜底在调用方：撞 UNIQUE 时自动换号重试（见 create_order_*）。
     */
    function next_ref($table, $prefix, $dateCol = 'created_at')
    {
        // 1) 表名白名单：只有这三张表有 ref 列
        $allowed = ['orders' => 'ORD', 'enquiries' => 'EN', 'quotes' => 'Q'];
        if (!isset($allowed[$table])) {
            throw new Exception('next_ref: unsupported table "' . $table . '"');
        }
        // 前缀会拼进 LIKE 模式，只留安全字符
        $prefix = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$prefix);
        if ($prefix === '') $prefix = $allowed[$table];

        $ymd  = date('Ymd');
        $like = $prefix . '-' . $ymd . '-%';
        $lock = 'next_ref_' . $table . '_' . $prefix;

        // 2) + 3)
        db_val('SELECT GET_LOCK(?, 10)', [$lock]);
        try {
            $maxSeq = (int)db_val(
                "SELECT IFNULL(MAX(CAST(SUBSTRING_INDEX(`ref`, '-', -1) AS UNSIGNED)), 0)
                 FROM `{$table}` WHERE `ref` LIKE ?",
                [$like], 0
            );
            return sprintf('%s-%s-%04d', $prefix, $ymd, $maxSeq + 1);
        } finally {
            db_val('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }
}

/**
 * 判断一个 PDO 异常是不是「唯一键冲突」（MySQL SQLSTATE 23000 / 码 1062）。
 * 用于流水号撞车后自动换号重试。
 */
if (!function_exists('is_duplicate_key')) {
    function is_duplicate_key($e)
    {
        if (!$e instanceof PDOException) return false;
        $code = isset($e->errorInfo[1]) ? (int)$e->errorInfo[1] : 0;
        return $e->getCode() === '23000' || $code === 1062;
    }
}

/* ============================ 登录态 ============================ */

if (!function_exists('client_id')) {
    function client_id()
    {
        return isset($_SESSION['client_id']) ? (int)$_SESSION['client_id'] : 0;
    }
}
if (!function_exists('require_client')) {
    function require_client($loginUrl = '/account.php')
    {
        if (!client_id()) {
            redirect(site_url($loginUrl));
        }
    }
}
if (!function_exists('admin_id')) {
    function admin_id()
    {
        return isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : 0;
    }
}
if (!function_exists('admin_user')) {
    function admin_user()
    {
        static $u = null;
        if ($u === null && admin_id()) {
            $u = db_one('SELECT * FROM admin_users WHERE id = ?', [admin_id()]);
        }
        return $u;
    }
}
/* 管理员后台「查看账单 / 协议」直读令牌（2026-10-15）：
   后台订单列表的 Contract / Invoice 链接自带一小时有效、按订单 ID 签名的 atok。
   发票/合同页即使跨域名 / 会话 cookie 未带上 admin 会话，也能凭令牌放行，
   不再依赖「管理员会话 cookie 必须跨页面共享」。已登录后台仍直接放行。 */
if (!function_exists('admin_deeplink_secret')) {
    function admin_deeplink_secret()
    {
        $k = cfg('app_key', '');
        if (!$k) $k = 'cntm-admin-deeplink-2026';
        return $k;
    }
}
if (!function_exists('admin_deeplink_token')) {
    function admin_deeplink_token($id)
    {
        $id = (int)$id;
        $hour = gmdate('YmdH');
        return substr(hash_hmac('sha256', $id . '|' . $hour, admin_deeplink_secret()), 0, 20);
    }
}
if (!function_exists('admin_deeplink_ok')) {
    function admin_deeplink_ok($id)
    {
        if (admin_id()) return true;            // 已登录后台，直接放行
        $t = trim((string)get('atok', ''));
        if ($t === '') return false;
        $id = (int)$id;
        if ($id <= 0) return false;
        // 允许前后一小时窗，避免整点边界刚好过期
        $hours = [gmdate('YmdH'), gmdate('YmdH', time() - 3600), gmdate('YmdH', time() + 3600)];
        foreach ($hours as $h) {
            $cand = substr(hash_hmac('sha256', $id . '|' . $h, admin_deeplink_secret()), 0, 20);
            if (hash_equals($cand, $t)) return true;
        }
        return false;
    }
}
if (!function_exists('require_admin')) {
    function require_admin($loginUrl = '/app/admin/login.php')
    {
        if (!admin_id()) {
            redirect(site_url($loginUrl));
        }
    }
}
/** 后台敏感文件查看权限 */
if (!function_exists('admin_can_sensitive')) {
    function admin_can_sensitive()
    {
        $u = admin_user();
        return $u && ($u['role'] === 'owner' || (int)$u['can_view_sensitive'] === 1);
    }
}
/** 是否负责人（唯一能管后台账号的角色） */
if (!function_exists('admin_is_owner')) {
    function admin_is_owner()
    {
        $u = admin_user();
        return $u && $u['role'] === 'owner';
    }
}
/** 只允许负责人访问（账号管理页用） */
if (!function_exists('require_owner')) {
    function require_owner($loginUrl = '/app/admin/login.php')
    {
        if (!admin_id()) redirect(site_url($loginUrl));
        if (!admin_is_owner()) {
            http_response_code(403);
            exit(lang() === 'zh' ? '403 — 只有负责人账号可以进入这一页。' : '403 — Owners only.');
        }
    }
}

/* --------------------------------------------------------------
 * 只读账号（viewer）写操作闸门 —— 2026-09-30 新增
 *
 * 之前 users.php 页面上那句提示写得很坦白：
 *   "Viewer is a UI-level convention: write actions are not blocked page by
 *    page yet, so treat viewer accounts as trusted."
 * 也就是说 viewer 只是「界面上不显示按钮」，真正的写接口谁都能 POST ——
 * 给财务一个 viewer 账号看数据，他照样能改报价、登记收款、作废订单。
 *
 * 现在收口在 _layout.php（22 个后台业务页全部要经过它）：
 * 只要是 POST，viewer 一律 403。一处设闸，全后台生效，
 * 将来新增页面也自动继承，不用再逐页记得判断。
 * -------------------------------------------------------------- */

/** 该管理员是不是只读账号（role = viewer） */
if (!function_exists('admin_is_readonly')) {
    function admin_is_readonly($u = null)
    {
        if ($u === null) $u = admin_user();
        if (!$u) return false;                 // 没登录/查不到 → 交给 require_admin 处理
        return (($u['role'] ?? '') === 'viewer');
    }
}

/** 403 提示页（viewer 撞上写操作时显示） */
if (!function_exists('admin_readonly_denied')) {
    function admin_readonly_denied($uri = '')
    {
        http_response_code(403);
        if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
        $back = 'javascript:history.back()';
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow">'
            . '<title>403 · Read-only account · EXAMPLE IP</title>'
            . '<style>'
            . 'body{margin:0;font:14px/1.7 -apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;'
            . 'background:#f5f7f9;color:#1f2937;display:flex;align-items:center;justify-content:center;min-height:100vh}'
            . '.card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 4px 20px rgba(15,23,42,.07);'
            . 'max-width:520px;padding:32px 34px;margin:20px}'
            . '.ic{width:52px;height:52px;border-radius:50%;background:#fef2f2;color:#dc2626;display:flex;'
            . 'align-items:center;justify-content:center;font-size:22px;font-weight:700;margin-bottom:16px}'
            . 'h1{font-size:18px;margin:0 0 10px}p{margin:0 0 8px;color:#4b5563}'
            . '.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;color:#6b7280;'
            . 'background:#f3f4f6;padding:2px 6px;border-radius:4px;word-break:break-all}'
            . 'a{display:inline-block;margin-top:18px;background:#0b3d91;color:#fff;text-decoration:none;'
            . 'padding:9px 18px;border-radius:8px;font-weight:600}'
            . '</style></head><body><div class="card">'
            . '<div class="ic">!</div>'
            . '<h1>403 — This account is read-only</h1>'
            . '<p>Your account has the <strong>Viewer</strong> role, which can browse the admin panel '
            . 'but cannot change anything. The action was <strong>not</strong> performed.</p>'
            . '<p>If you need write access, ask an owner to change your role on the Users page.</p>'
            . ($uri !== '' ? '<p class="mono">' . h($uri) . '</p>' : '')
            . '<a href="' . $back . '">Go back</a>'
            . '</div></body></html>';
        exit;
    }
}

/**
 * 调用时机：_layout.php 里 require_admin() 之后（所有后台业务页都会经过）。
 * 只拦 POST —— 后台所有写操作都是 POST，列表/导出/下载都是 GET，不影响浏览。
 */
if (!function_exists('admin_guard_readonly')) {
    function admin_guard_readonly()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
        if (!admin_id()) return;                 // require_admin() 已经处理过未登录
        if (!admin_is_readonly()) return;

        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        app_log('blocked write by read-only admin #' . admin_id() . ' -> ' . $uri, 'WARN');
        admin_readonly_denied($uri);
    }
}

/* ============================ 业务小工具 ============================ */

/** 金额显示 */
if (!function_exists('money')) {
    function money($amount, $currency = null)
    {
        if ($currency === null) $currency = setting('currency', cfg('pricing.currency', 'USD'));
        $sym = ['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'CNY' => '¥'];
        return ($sym[$currency] ?? '') . number_format((float)$amount, 2) . ' ' . $currency;
    }
}
/**
 * CSV 单元格清洗：防 Excel 公式注入。
 *
 * 导出的表格里只要有「客户自己填的」内容（名字、备注、邮箱），就有被做成
 * =cmd|'/c calc'!A1 这类公式的风险——Excel 打开会执行。做法：单元格首字符是
 * = + - @ 时前置一个单引号，Excel 就当纯文本了。数字原样输出（负号不能乱加引号）。
 */
if (!function_exists('csv_cell')) {
    function csv_cell($v)
    {
        if (is_int($v) || is_float($v)) return $v;      // 数字照原样写，负号不能被加引号
        $v = (string)($v === null ? '' : $v);
        $v = str_replace(["\r\n", "\r", "\n"], ' ', $v); // 换行会撑破 CSV 行结构
        $c = $v !== '' ? $v[0] : '';
        if ($c === '=' || $c === '+' || $c === '-' || $c === '@' || $c === "\t") $v = "'" . $v;
        return $v;
    }
}

/** 写日志到 app/storage/app.log */
if (!function_exists('app_log')) {
    function app_log($msg, $level = 'INFO')
    {
        $dir = dirname(__DIR__) . '/storage';
        if (!is_dir($dir)) return;
        $line = sprintf("[%s] %-5s %s%s", date('Y-m-d H:i:s'), $level, $msg, PHP_EOL);
        @file_put_contents($dir . '/app.log', $line, FILE_APPEND);
    }
}

/**
 * 后台操作留痕（admin_log 表，003 迁移创建）。
 *
 * 为什么需要：客户几年后回来说「当初那份受理通知书找不到了」，我们得能回答
 * 「谁在什么时候上传的、后来谁改过」。这类问题没有操作日志就只能靠猜。
 *
 * 设计上刻意「哑」：表不存在就静默跳过，绝不让审计需求把主流程搞挂。
 */
if (!function_exists('admin_log_action')) {
    function admin_log_action($action, $targetType = '', $targetId = null, $detail = '')
    {
        try {
            db_exec(
                'INSERT INTO `admin_log` (`admin_id`,`action`,`target_type`,`target_id`,`detail`,`ip`) VALUES (?,?,?,?,?,?)',
                [admin_id(), (string)$action, (string)$targetType, $targetId === null ? null : (int)$targetId, (string)$detail, client_ip()]
            );
        } catch (Throwable $e) {
            // 表还没建 / 权限不足 —— 审计留痕失败不该阻断业务，只记文件日志
            app_log('admin_log_action skipped: ' . $e->getMessage(), 'WARN');
        }
    }
}

/**
 * 取最近后台操作（详情页展示「谁动过这个单子」）
 */
if (!function_exists('admin_log_recent')) {
    function admin_log_recent($targetType, $targetId, $limit = 20)
    {
        try {
            return db_all(
                'SELECT l.*, a.name AS admin_name, a.username
                 FROM `admin_log` l LEFT JOIN `admin_users` a ON a.id = l.admin_id
                 WHERE l.target_type = ? AND l.target_id = ?
                 ORDER BY l.id DESC LIMIT ' . (int)$limit,
                [(string)$targetType, (int)$targetId]
            );
        } catch (Throwable $e) {
            return [];
        }
    }
}
