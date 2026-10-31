<?php
/**
 * 图样 AJAX 上传（询价页专用）
 *
 * 时机问题：客户选图样时工单还没提交，没有 enquiry_id。
 * 因此先落 documents 表（owner_type='enquiry', owner_id=0, kind='device'）作为「待挂」文件，
 * 正式提交工单时由 enquiry_submit.php 把它挂到真实 enquiry_id 上。
 *
 * 返回 JSON：{ok:true, id:12, name:"logo.png", size:102400}
 *        或 {ok:false, message:"...", error:"..."}
 *
 * 设计约束（2026-09-29 事故后加固）：
 *   本接口无论出什么事，都必须返回一段合法 JSON。
 *   之前出现过「文件已经落盘、后台能看见，前端却报 Upload failed」——
 *   根因是落盘之后抛了未捕获异常，PHP 直接 500 且输出为空，前端 JSON.parse('') 炸掉。
 *   所以这里加了 shutdown handler + Throwable 捕获双保险。
 */
require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');

/** 统一出口：保证任何路径都只输出一段 JSON，且只输出一次 */
$__sent = false;
function dv_out(array $payload, $code = 200)
{
    global $__sent;
    if ($__sent) return;
    $__sent = true;

    /* 接口是匿名可访问的（客户提交询价前先传商标图样），所以 error / diag 里的
     * PDO 原文（列名、SQLSTATE、数据库用户名、文件行号）不能无条件回传 —— 2026-09-29
     * 排查时按需打开：把 config.php 的 debug 置 true，或登录后台后再访问本接口。 */
    $verbose = (bool)cfg('debug') || !empty($_SESSION['admin_id']);
    if (!$verbose) {
        unset($payload['error'], $payload['diag']);
    }

    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * 只在「失败」时附加的最小结构诊断（不含任何业务数据，只有表名/列名）。
 * 目的：一次回传就能定位是表不存在、列缺失还是别的，避免来回猜测。
 * 上线稳定后可以把 $withDiag 改成 false。
 */
function dv_diag()
{
    $d = ['php' => PHP_VERSION];
    try {
        $n = (int)db_val("SELECT COUNT(*) FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents'", [], 0);
        $d['table'] = $n > 0 ? 'documents:yes' : 'documents:MISSING';
        if ($n > 0) {
            $cols = db_all("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents'
                            ORDER BY ORDINAL_POSITION");
            $names = [];
            foreach ($cols as $c) $names[] = $c['COLUMN_NAME'];
            $d['cols'] = implode(',', $names);
            $kind = db_one("SHOW COLUMNS FROM `documents` LIKE 'kind'");
            if ($kind && !empty($kind['Type'])) $d['kind_enum'] = $kind['Type'];
        }
    } catch (Throwable $e) {
        $d['probe'] = 'failed: ' . $e->getMessage();
    }
    $d['finfo'] = function_exists('finfo_open') ? 'yes' : 'no';
    $d['gd']    = function_exists('getimagesize') ? 'yes' : 'no';
    return $d;
}

/** 兜底一：致命错误（fatal / parse）也要吐 JSON，不能吐空白 */
register_shutdown_function(function () {
    $e = error_get_last();
    if (!$e) return;
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int)$e['type'], $fatal, true)) return;
    $where = basename((string)$e['file']) . ':' . (int)$e['line'];
    app_log('device upload FATAL: ' . $e['message'] . ' @ ' . $where, 'ERROR');
    dv_out([
        'ok'      => false,
        'message' => t('enq.device_fail'),
        'error'   => 'fatal: ' . $e['message'] . ' @ ' . $where,
        'diag'    => dv_diag(),
    ], 500);
});

try {

    /* ---------- 1. CSRF（token 由页面 csrf_field() 提供，前端放进 FormData） ---------- */
    if (!csrf_check()) {
        dv_out(['ok' => false, 'message' => t('enq.err_csrf'), 'error' => 'csrf'], 419);
    }

    /* ---------- 2. 频率限制（单独桶，不与询价提交互相挤占） ---------- */
    if (!rate_limit('upload', 30)) {
        dv_out(['ok' => false, 'message' => t('enq.err_rate'), 'error' => 'rate'], 429);
    }

    /* ---------- 3. 文件 ---------- */
    if (empty($_FILES['device_file']) || empty($_FILES['device_file']['tmp_name'])) {
        dv_out(['ok' => false, 'message' => t('enq.device_none'), 'error' => 'no file']);
    }

    $maxMb = (int)cfg('storage.max_upload_mb', 8);
    $up    = save_upload('device_file', 'enquiry', 0, 'device', 0, 'client');

    if (!$up['ok']) {
        app_log('device ajax upload failed: ' . $up['error'], 'WARN');
        $err = (string)$up['error'];
        if ($err === 'file too large') {
            $msg = t('enq.device_too_large', ['mb' => $maxMb]);
        } elseif (strpos($err, 'unsupported file type') === 0) {
            $msg = t('enq.device_bad_type');
        } elseif ($err === 'storage dir not writable' || $err === 'move failed') {
            $msg = t('enq.device_server_fail');
        } elseif (strpos($err, 'db write failed') === 0) {
            $msg = t('enq.device_server_fail');
        } else {
            $msg = t('enq.device_fail');
        }
        $out = ['ok' => false, 'message' => $msg, 'error' => $err];
        if (strpos($err, 'db write failed') === 0) $out['diag'] = dv_diag();
        dv_out($out, 400);
    }

    /* ---------- 4. 顺手清理过期孤儿（选了图样但始终没提交工单） ---------- */
    try {
        $old = db_all("SELECT id, stored_name FROM documents
                       WHERE owner_type = 'enquiry' AND owner_id = 0 AND kind = 'device'
                         AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
                       LIMIT 50");
        foreach ($old as $o) {
            $fp = storage_path() . DIRECTORY_SEPARATOR . basename((string)$o['stored_name']);
            if (is_file($fp)) @unlink($fp);
            db_exec('DELETE FROM documents WHERE id = ?', [(int)$o['id']]);
        }
    } catch (Throwable $e) {
        app_log('orphan device cleanup failed: ' . $e->getMessage(), 'WARN');
    }

    dv_out([
        'ok'   => true,
        'id'   => (int)$up['id'],
        'name' => substr((string)$_FILES['device_file']['name'], 0, 190),
        'size' => (int)$_FILES['device_file']['size'],
    ]);

} catch (Throwable $e) {
    /* 兜底二：任何未捕获异常 */
    app_log('device upload exception: ' . $e->getMessage(), 'ERROR');
    dv_out([
        'ok'      => false,
        'message' => t('enq.device_fail'),
        'error'   => 'exception: ' . $e->getMessage()
                     . ' @ ' . basename((string)$e->getFile()) . ':' . (int)$e->getLine(),
        'diag'    => dv_diag(),
    ], 500);
}
