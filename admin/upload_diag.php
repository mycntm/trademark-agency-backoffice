<?php
/**
 * 上传诊断（临时工具，需管理员登录）
 *
 * 用途：客户报「文件类型不支持」时，把同一个文件传上来，
 *       一次性看清服务器端到底探测到了什么：
 *         - fileinfo / GD 扩展是否可用
 *         - finfo 探测结果
 *         - getimagesize 结果
 *         - 文件头 16 字节（hex + ASCII）
 *         - 最终判定是否会放行
 *
 * 本页不做入库，只读取临时文件内容，安全无副作用。
 * 排查完可直接删除本文件。
 */
require_once __DIR__ . '/../bootstrap.php';
/*
 * admin_header() / admin_footer() / ad_flash() 都定义在 _layout.php 里，
 * bootstrap 只加载 app/lib/*（不含后台布局）。少了这一行，未登录时在
 * require_admin() 处就 302 了看不出来，**登录后**走到 admin_header() 才
 * 「Call to undefined function」→ 500。2026-10-06 修。
 */
require_once __DIR__ . '/_layout.php';
require_admin();

$out = null;

if (is_post() && post('act') === 'diag') {
    if (!csrf_check()) {
        $out = ['err' => 'CSRF failed. Refresh and try again.'];
    } else {
        $err = (int)($_FILES['f']['error'] ?? UPLOAD_ERR_NO_FILE);
        $tmp = (string)($_FILES['f']['tmp_name'] ?? '');

        if ($tmp === '' && $err === UPLOAD_ERR_NO_FILE) {
            $out = ['err' => 'No file selected — pick a file and submit again.'];
        } elseif ($tmp === '' || $err !== UPLOAD_ERR_OK) {
            /*
             * 文件没到 PHP —— 这正是最需要诊断的一类失败（后台常见的 'no file'）。
             * 只报「没收到」等于什么都没说：必须把 PHP 的错误码和服务端**实际生效**的
             * 限额一起打出来，否则改完 php.ini / .user.ini 也不知道到底有没有生效。
             * 2026-10-06 补。
             */
            $out = [
                'reject' => true,
                'size'   => (int)($_FILES['f']['size'] ?? 0),
                'code'   => $err,
                'reason' => upload_err_text($err),
                'ini'    => [
                    'upload_max_filesize' => (string)ini_get('upload_max_filesize'),
                    'post_max_size'       => (string)ini_get('post_max_size'),
                    'max_file_uploads'    => (string)ini_get('max_file_uploads'),
                    'upload_tmp_dir'      => (string)(ini_get('upload_tmp_dir') ?: '(未设置 → 用系统临时目录)'),
                    'file_uploads'        => ini_get('file_uploads') ? 'On' : 'OFF',
                    'ini 文件'             => (string)(php_ini_loaded_file() ?: '(none)'),
                    '站点 .user.ini'       => is_file(__DIR__ . '/../../.user.ini')
                                                ? '存在 ← 它的优先级高于 php.ini，先看它'
                                                : '无',
                    'PHP 版本'             => PHP_VERSION,
                ],
            ];
        } else {
            $name = (string)$_FILES['f']['name'];
            $size = (int)$_FILES['f']['size'];

            $finfoOk     = function_exists('finfo_open');
            $finfoResult = '(finfo unavailable)';
            if ($finfoOk) {
                $fo = @finfo_open(FILEINFO_MIME_TYPE);
                if ($fo) { $m = @finfo_file($fo, $tmp); finfo_close($fo); $finfoResult = $m ? $m : '(empty)'; }
                else     { $finfoResult = '(finfo_open returned false — magic database missing?)'; }
            }

            $gsOk     = function_exists('getimagesize');
            $gsResult = '(getimagesize unavailable)';
            if ($gsOk) {
                $gi = @getimagesize($tmp);
                $gsResult = $gi && !empty($gi['mime'])
                    ? $gi['mime'] . '  (' . (int)$gi[0] . '×' . (int)$gi[1] . ')'
                    : 'false — not recognised as an image';
            }

            $head    = (string)@file_get_contents($tmp, false, null, 0, 16);
            $hex     = strtoupper(bin2hex($head));
            $hexFmt  = trim(chunk_split($hex, 2, ' '));
            $ascii   = preg_replace('/[^\x20-\x7E]/', '.', $head);

            $allow = cfg('storage.allowed_mime', []);
            $final = detect_mime($tmp);
            $pass1 = in_array($final, $allow, true);
            $alt   = $pass1 ? '' : detect_mime_fallback($tmp);
            $pass2 = ($alt !== '' && in_array($alt, $allow, true));

            $out = [
                'name'         => $name,
                'size'         => $size,
                'php_err'      => $err,
                'ext_loaded'   => 'fileinfo: ' . ($finfoOk ? 'yes' : 'NO') . '   |   getimagesize: ' . ($gsOk ? 'yes' : 'NO'),
                'finfo'        => $finfoResult,
                'getimagesize' => $gsResult,
                'head_hex'     => $hexFmt,
                'head_ascii'   => $ascii,
                'allow'        => implode(', ', $allow),
                'step1'        => $final,
                'step2'        => $alt === '' ? '(no fallback match)' : $alt,
                'verdict'      => ($pass1 || $pass2)
                    ? 'ACCEPTED — will be stored as: ' . ($pass1 ? $final : $alt)
                    : 'REJECTED — genuinely unsupported by the whitelist',
                'max_mb'       => (int)cfg('storage.max_upload_mb', 8),
            ];
        }
    }
}

admin_header('Upload diagnostics', 'settings');
?>
<div class="ad-card">
    <h2>Upload diagnostics</h2>
    <p class="lg-sm" style="opacity:.75;margin-bottom:14px;">
        Upload the exact file a customer could not submit. Nothing is stored — this page only reads the
        temporary upload and reports what the server detects.
    </p>

    <form method="post" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="act" value="diag">
        <div class="form-row">
            <div>
                <label for="f">File</label>
                <input type="file" id="f" name="f" required>
            </div>
        </div>
        <button class="btn btn-p" type="submit">Run diagnostics</button>
    </form>
</div>

<?php if ($out): ?>
<div class="ad-card">
    <h2>Result</h2>
    <?php if (isset($out['err'])): ?>
        <?php echo ad_flash($out['err'], 'bad'); ?>
    <?php elseif (!empty($out['reject'])): ?>
        <?php echo ad_flash(
            '文件在到达 PHP 之前就被拒收了 —— ' . $out['reason']
            . '。这不是程序的问题，是服务器限额，改下面的值后再来一次。',
            'bad'
        ); ?>
        <table class="ad-tb" style="width:100%;font-size:13px;">
            <tr>
                <th style="width:30%;text-align:left;padding:7px 10px;background:#f8fafc;font-weight:600;">PHP 上传错误码</th>
                <td style="padding:7px 10px;font-family:ui-monospace,Menlo,Consolas,monospace;">
                    <?php echo (int)$out['code']; ?>
                    <?php
                    $errNames = [
                        UPLOAD_ERR_INI_SIZE   => 'UPLOAD_ERR_INI_SIZE（超过 upload_max_filesize）',
                        UPLOAD_ERR_FORM_SIZE  => 'UPLOAD_ERR_FORM_SIZE（超过表单 MAX_FILE_SIZE）',
                        UPLOAD_ERR_PARTIAL    => 'UPLOAD_ERR_PARTIAL（只收到一部分）',
                        UPLOAD_ERR_NO_TMP_DIR => 'UPLOAD_ERR_NO_TMP_DIR（没有可写的临时目录）',
                        UPLOAD_ERR_CANT_WRITE => 'UPLOAD_ERR_CANT_WRITE（临时文件写不进去：磁盘满/无权限）',
                        UPLOAD_ERR_EXTENSION  => 'UPLOAD_ERR_EXTENSION（某个扩展中止了上传）',
                    ];
                    echo isset($errNames[(int)$out['code']]) ? ' — ' . h($errNames[(int)$out['code']]) : '';
                    ?>
                </td>
            </tr>
            <?php if ((int)$out['size'] > 0): ?>
            <tr>
                <th style="text-align:left;padding:7px 10px;background:#f8fafc;font-weight:600;">浏览器报告的大小</th>
                <td style="padding:7px 10px;font-family:ui-monospace,Menlo,Consolas,monospace;">
                    <?php echo number_format((int)$out['size']); ?> bytes
                    (<?php echo number_format((int)$out['size'] / 1048576, 2); ?> MB)
                </td>
            </tr>
            <?php endif; ?>
            <?php foreach ($out['ini'] as $k => $v): ?>
            <tr>
                <th style="text-align:left;padding:7px 10px;background:#f8fafc;font-weight:600;">
                    <?php echo h((string)$k); ?>
                </th>
                <td style="padding:7px 10px;font-family:ui-monospace,Menlo,Consolas,monospace;word-break:break-all;">
                    <?php echo h((string)$v); ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
    <table class="ad-tb" style="width:100%;font-size:13px;">
        <?php
        $rows = [
            'File name'                => $out['name'],
            'Size'                     => number_format($out['size']) . ' bytes (limit ' . $out['max_mb'] . ' MB)',
            'PHP upload error code'    => (string)$out['php_err'] . '  (0 = UPLOAD_ERR_OK)',
            'Extensions'               => $out['ext_loaded'],
            'finfo detection'          => $out['finfo'],
            'getimagesize detection'   => $out['getimagesize'],
            'First 16 bytes (hex)'     => $out['head_hex'],
            'First 16 bytes (ASCII)'   => $out['head_ascii'],
            'Whitelist'                => $out['allow'],
            'Step 1 — finfo'           => $out['step1'],
            'Step 2 — fallback'        => $out['step2'],
        ];
        foreach ($rows as $k => $v): ?>
            <tr>
                <th style="width:30%;text-align:left;padding:7px 10px;background:#f8fafc;font-weight:600;"><?php echo h($k); ?></th>
                <td style="padding:7px 10px;font-family:ui-monospace,Menlo,Consolas,monospace;word-break:break-all;"><?php echo h((string)$v); ?></td>
            </tr>
        <?php endforeach; ?>
        <tr>
            <th style="text-align:left;padding:7px 10px;background:#f8fafc;font-weight:600;">Verdict</th>
            <td style="padding:7px 10px;font-weight:700;color:<?php echo strpos($out['verdict'], 'ACCEPTED') === 0 ? '#065f46' : '#991b1b'; ?>;">
                <?php echo h($out['verdict']); ?>
            </td>
        </tr>
    </table>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="ad-card">
    <h2>How to read this</h2>
    <ul style="font-size:13px;line-height:1.75;color:#4b5563;">
        <li><b>finfo detection = application/octet-stream</b> while <b>Extensions: fileinfo: NO</b>
            → the fileinfo extension is not installed on this server. The magic-number fallback handles it.</li>
        <li><b>head_hex</b> tells you the real format regardless of the file name:
            <code>FF D8 FF</code> = JPEG, <code>89 50 4E 47</code> = PNG,
            <code>47 49 46 38</code> = GIF, <code>52 49 46 46 … 57 45 42 50</code> = WebP,
            <code>25 50 44 46</code> = PDF, <code>… 66 74 79 70</code> = AVIF/HEIC.</li>
        <li>If <b>PHP upload error code</b> is not 0, the file never reached PHP — raise
            <code>upload_max_filesize</code> / <code>post_max_size</code>.</li>
        <li><b>Already raised the limit but this page still shows the old value</b> —
            compare <code>php.ini loaded</code> above with the file you edited. BT keeps
            <b>one php.ini per PHP version</b>; editing another version's file does nothing.
            If the path matches, check <code>site .user.ini</code> (it overrides php.ini)
            and the FPM pool's <code>php_admin_value[]</code> (nothing overrides that).
            Remember: <code>upload_max_filesize</code> and <code>post_max_size</code> must
            <b>both</b> be raised.</li>
        <li><b>Two separate ceilings</b>: PHP's <code>upload_max_filesize</code> rejects the file
            before it arrives (error code 1); the app's <code>storage.max_upload_mb</code>
            (in <code>app/config.php</code>, currently shown above) rejects it after. A file can
            pass one and fail the other with a different message.</li>
    </ul>
    <p class="lg-sm" style="margin-top:12px;opacity:.7;">Delete this file once you are done: <code>app/admin/upload_diag.php</code></p>
</div>

<?php
/* ---------- 服务端环境 / 库结构（只读） ---------- */
$cols   = [];
$tblMsg = '';
try {
    $cols = db_all('SHOW COLUMNS FROM `documents`');
} catch (Throwable $e) {
    $tblMsg = 'documents table unreachable: ' . $e->getMessage();
}
?>
<div class="ad-card">
    <h2>Server &amp; table state</h2>
    <table class="ad-tb" style="width:100%;font-size:13px;">
        <?php
        $env = [
            'PHP version'            => PHP_VERSION . '   (' . PHP_SAPI . ')',
            /*
             * 下面四行专治「我明明改了 php.ini 却没生效」（2026-10-06 真实卡点）：
             * 限额还是默认值时，**先看这份 ini 路径是不是你改的那份** ——
             * 宝塔一台机器可并存多个 PHP 版本，每个版本一份 php.ini，改错版本毫无反应。
             * 路径对了再看 .user.ini（优先级高于 php.ini，静默覆盖）
             * 和 FPM 池的 php_admin_value[]（谁都覆盖不了它）。
             */
            'php.ini loaded'         => (string)(php_ini_loaded_file() ?: '(none)'),
            'ini files scanned'      => (string)(php_ini_scanned_files() ?: '(none)'),
            'site .user.ini'         => is_file(__DIR__ . '/../../.user.ini')
                                        ? 'EXISTS ← 优先级高于 php.ini，先看它'
                                        : 'none',
            'upload_max_filesize'    => (string)ini_get('upload_max_filesize'),
            'post_max_size'          => (string)ini_get('post_max_size'),
            'max_file_uploads'       => (string)ini_get('max_file_uploads'),
            'upload_tmp_dir'         => (string)(ini_get('upload_tmp_dir') ?: '(未设置 → 系统临时目录)'),
            'file_uploads'           => ini_get('file_uploads') ? 'on' : 'OFF',
            'app limit (config.php)' => (int)cfg('storage.max_upload_mb', 8) . ' MB  (storage.max_upload_mb —— 与 PHP 限额是两层)',
            'fileinfo ext'           => function_exists('finfo_open') ? 'yes' : 'NO',
            'getimagesize'           => function_exists('getimagesize') ? 'yes' : 'NO',
            'storage.path'           => storage_path(),
            'storage dir writable'   => is_writable(storage_path()) ? 'yes' : 'NO',
            'allowed_mime'           => implode(', ', cfg('storage.allowed_mime', [])),
        ];
        foreach ($env as $k => $v): ?>
            <tr>
                <th style="width:30%;text-align:left;padding:6px 10px;background:#f8fafc;font-weight:600;"><?php echo h($k); ?></th>
                <td style="padding:6px 10px;font-family:ui-monospace,Menlo,Consolas,monospace;word-break:break-all;"><?php echo h((string)$v); ?></td>
            </tr>
        <?php endforeach; ?>
        <tr>
            <th style="text-align:left;padding:6px 10px;background:#f8fafc;font-weight:600;">documents columns</th>
            <td style="padding:6px 10px;font-family:ui-monospace,Menlo,Consolas,monospace;word-break:break-all;">
                <?php
                if ($tblMsg !== '') {
                    echo h($tblMsg);
                } else {
                    $names = [];
                    foreach ($cols as $c) $names[] = $c['Field'];
                    echo h(implode(', ', $names));
                }
                ?>
            </td>
        </tr>
    </table>
</div>

<?php
/* ---------- ini 溯源：限额究竟由哪个文件决定（只读） ----------
 * 「明明改了 php.ini 却不生效」的唯一确定答案在这里：
 *   php.ini 里的值（global_value）  vs  最终生效的值（local_value）
 * 两者不一致 = 有更高优先级的东西在覆盖它，只有两处可能：
 *   .user.ini（PHP_INI_PERDIR 及以上可被它改） 与 FPM 池的 php_admin_value[]（不可被覆盖）。
 * 2026-10-06 加。 */
$iniPath = (string)php_ini_loaded_file();
$iniDir  = $iniPath !== '' ? dirname($iniPath) : '';
$userIni = dirname(__DIR__, 2) . '/.user.ini';

/** 只挑与上传/限额相关的行，避免把整份 ini 摊出来 */
$pickLines = function ($file) {
    $res = ['state' => '', 'hits' => []];
    if (!is_file($file)) { $res['state'] = 'not present'; return $res; }
    if (!is_readable($file)) { $res['state'] = 'NOT readable by the PHP user'; return $res; }
    $rows = @file($file, FILE_IGNORE_NEW_LINES);
    if ($rows === false) { $res['state'] = 'read failed'; return $res; }
    $res['state'] = count($rows) . ' lines · modified ' . date('Y-m-d H:i:s', (int)@filemtime($file));
    $pat = '/^[\s;]*(upload_max_filesize|post_max_size|memory_limit|upload_tmp_dir|file_uploads'
         . '|user_ini\.filename|user_ini\.cache_ttl|disable_functions|extension\s*=\s*\S*fileinfo)\s*=/i';
    foreach ($rows as $i => $ln) {
        if (preg_match($pat, $ln) || stripos($ln, 'fileinfo') !== false) {
            $res['hits'][] = ($i + 1) . ': ' . trim($ln);
        }
    }
    return $res;
};

$iniInfo = $pickLines($iniPath);
$uiInfo  = $pickLines($userIni);

/* access 位说明：1=USER 2=PERDIR 4=SYSTEM 6=PERDIR|SYSTEM 7=ALL。
 * upload_max_filesize / post_max_size 都是 PERDIR → **.user.ini 能改它们**。 */
$accText = [1 => 'USER', 2 => 'PERDIR ← .user.ini 能改', 4 => 'SYSTEM', 6 => 'PERDIR|SYSTEM ← .user.ini 能改', 7 => 'ALL'];
$all     = @ini_get_all(null, true) ?: [];
$directives = [];
foreach (['upload_max_filesize','post_max_size','memory_limit','max_execution_time','upload_tmp_dir','file_uploads','user_ini.filename','user_ini.cache_ttl','disable_functions'] as $d) {
    if (!isset($all[$d])) { $directives[$d] = ['(n/a)', '(n/a)', '', '']; continue; }
    $e = $all[$d];
    $g = (string)($e['global_value'] ?? '');
    $l = (string)($e['local_value'] ?? '');
    $k = (int)($e['access'] ?? 0);
    $directives[$d] = [
        $g === '' ? '(空 → 用内置默认)' : $g,
        $l === '' ? '(空 → 用内置默认)' : $l,
        $g === $l ? '一致' : '被覆盖 ❌ ← 看下面的文件',
        $accText[$k] ?? (string)$k,
    ];
}

/* FPM 池配置：php_admin_value[] 优先级最高，ini 与 .user.ini 都压不过它 */
$fpmHits = [];
foreach (($iniDir !== '' ? (array)glob($iniDir . '/php-fpm.d/*.conf') : []) as $f) {
    if (!is_readable($f)) { $fpmHits[] = basename($f) . ' → not readable'; continue; }
    foreach ((array)@file($f, FILE_IGNORE_NEW_LINES) as $i => $ln) {
        if (preg_match('/^\s*(php_admin_value|php_value|php_admin_flag|php_flag)\s*\[/i', $ln)) {
            $fpmHits[] = basename($f) . ':' . ($i + 1) . '  ' . trim($ln);
        }
    }
}
?>
<div class="ad-card">
    <h2>Where the limits actually come from</h2>
    <p class="lg-sm" style="opacity:.75;margin-bottom:14px;">
        <b>php.ini value</b> = what the loaded php.ini says.
        <b>Effective value</b> = what PHP really uses.
        If the two differ, something with higher priority is overriding it.
    </p>
    <table class="ad-tb" style="width:100%;font-size:13px;">
        <tr>
            <th style="width:20%;text-align:left;padding:7px 10px;background:#f8fafc;">Directive</th>
            <th style="text-align:left;padding:7px 10px;background:#f8fafc;">php.ini value</th>
            <th style="text-align:left;padding:7px 10px;background:#f8fafc;">Effective value</th>
            <th style="text-align:left;padding:7px 10px;background:#f8fafc;">Status</th>
            <th style="text-align:left;padding:7px 10px;background:#f8fafc;">Scope</th>
        </tr>
        <?php foreach ($directives as $d => $r): ?>
        <tr>
            <th style="text-align:left;padding:7px 10px;background:#f8fafc;font-weight:600;"><?php echo h($d); ?></th>
            <td style="padding:7px 10px;font-family:ui-monospace,Menlo,Consolas,monospace;word-break:break-all;"><?php echo h($r[0]); ?></td>
            <td style="padding:7px 10px;font-family:ui-monospace,Menlo,Consolas,monospace;word-break:break-all;font-weight:<?php echo $r[0] === $r[1] ? '400' : '700'; ?>;color:<?php echo $r[0] === $r[1] ? 'inherit' : '#991b1b'; ?>;"><?php echo h($r[1]); ?></td>
            <td style="padding:7px 10px;"><?php echo h($r[2]); ?></td>
            <td style="padding:7px 10px;font-size:12px;opacity:.8;"><?php echo h($r[3]); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <h3 style="font-size:14px;margin:18px 0 6px;">Loaded php.ini — <span style="font-weight:400;"><?php echo h($iniPath); ?></span></h3>
    <p class="lg-sm" style="margin:0 0 6px;opacity:.75;"><?php echo h($iniInfo['state']); ?></p>
    <pre style="font-size:12px;line-height:1.6;background:#f8fafc;border:1px solid #e5e7eb;border-radius:6px;padding:10px;margin:0;white-space:pre-wrap;word-break:break-all;"><?php
        echo h($iniInfo['hits'] ? implode("\n", $iniInfo['hits']) : '(no matching lines)');
    ?></pre>

    <h3 style="font-size:14px;margin:18px 0 6px;">Site .user.ini — <span style="font-weight:400;"><?php echo h($userIni); ?></span></h3>
    <p class="lg-sm" style="margin:0 0 6px;opacity:.75;">
        <?php echo h($uiInfo['state']); ?>
        — this file <b>overrides php.ini</b> for PERDIR directives, and is re-read at most every
        <code>user_ini.cache_ttl</code> seconds (default 300), so wait ~5 min after editing it.
    </p>
    <pre style="font-size:12px;line-height:1.6;background:#f8fafc;border:1px solid #e5e7eb;border-radius:6px;padding:10px;margin:0;white-space:pre-wrap;word-break:break-all;"><?php
        echo h($uiInfo['hits'] ? implode("\n", $uiInfo['hits']) : '(no matching lines)');
    ?></pre>

    <h3 style="font-size:14px;margin:18px 0 6px;">FPM pool — <span style="font-weight:400;"><?php echo h($iniDir !== '' ? $iniDir . '/php-fpm.d/*.conf' : '(unknown)'); ?></span></h3>
    <p class="lg-sm" style="margin:0 0 6px;opacity:.75;">
        <code>php_admin_value[...]</code> beats everything — neither php.ini nor .user.ini can override it.
    </p>
    <pre style="font-size:12px;line-height:1.6;background:#f8fafc;border:1px solid #e5e7eb;border-radius:6px;padding:10px;margin:0;white-space:pre-wrap;word-break:break-all;"><?php
        echo h($fpmHits ? implode("\n", $fpmHits) : '(no php_value / php_admin_value lines found — or the directory is not readable)');
    ?></pre>
</div>

<?php admin_footer(); ?>
