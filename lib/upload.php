<?php
/**
 * upload.php —— 文件上传与受控下载
 *
 * 规则：
 *   - 白名单 MIME（用 finfo 探测真实类型，不信 $_FILES['type']）
 *   - 随机文件名保存，原始文件名只入库不落盘
 *   - 目录禁止直接访问（.htaccess Deny from all），下载必须走 PHP 鉴权
 *   - 敏感文件（委托书、身份证件）下载写入 document_access_log
 */

if (!function_exists('storage_path')) {
    function storage_path()
    {
        $p = cfg('storage.path');
        if (!is_dir($p)) @mkdir($p, 0775, true);
        return rtrim($p, '/\\');
    }
}

if (!function_exists('detect_mime')) {
    function detect_mime($tmp)
    {
        if (function_exists('finfo_open')) {
            $f = @finfo_open(FILEINFO_MIME_TYPE);
            if ($f) { $m = @finfo_file($f, $tmp); finfo_close($f); if ($m) return $m; }
        }
        return 'application/octet-stream';
    }
}

/**
 * finfo 识别失败/误判时的兜底探测。
 * 旧版 libmagic 不认识 AVIF/HEIC 这类新格式，会返回 application/octet-stream，
 * 导致「明明是图却说不支持」。这里用文件头直接判，比扩展名可靠、比 finfo 更新。
 */
if (!function_exists('detect_mime_fallback')) {
    function detect_mime_fallback($tmp, $nameHint = '')
    {
        // 1) 文件魔数：不依赖任何扩展，最可靠。判的是真实字节，不是扩展名。
        $head = (string)@file_get_contents($tmp, false, null, 0, 12);
        if ($head !== '') {
            if (substr($head, 0, 3) === "\xFF\xD8\xFF")                 return 'image/jpeg';
            if (substr($head, 0, 8) === "\x89PNG\r\n\x1A\n")            return 'image/png';
            if (substr($head, 0, 6) === 'GIF87a' || substr($head, 0, 6) === 'GIF89a') return 'image/gif';
            if (substr($head, 0, 4) === 'RIFF' && strlen($head) >= 12 && substr($head, 8, 4) === 'WEBP') return 'image/webp';
            if (substr($head, 0, 5) === '%PDF-')                         return 'application/pdf';
            // ISO-BMFF：第 5-8 字节 'ftyp'，第 9-12 字节为品牌
            if (strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp') {
                $brand = substr($head, 8, 4);
                if (in_array($brand, ['avif', 'avis'], true)) return 'image/avif';
                if (in_array($brand, ['heic', 'heix', 'hevc', 'mif1'], true)) return 'image/heic';
            }
            /*
             * Office 文件：先用魔数确认「它确实是这类容器」，再用扩展名分辨具体是哪一种。
             * 顺序不能反 —— 扩展名只作为同为 OLE2 / 同为 ZIP 时的细分依据，
             * 因此把 a.exe 改名成 a.docx 也骗不过前面的魔数检查。
             */
            if (substr($head, 0, 8) === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {   // OLE2：doc / xls / ppt
                return mime_from_office_hint($nameHint, 'doc');
            }
            if (substr($head, 0, 4) === "PK\x03\x04") {                          // ZIP：docx / xlsx / pptx
                return mime_from_office_hint($nameHint, 'docx');
            }
        }
        // 2) getimagesize 再补一刀（能覆盖部分 GD 认识而魔数表没列的格式）
        if (function_exists('getimagesize')) {
            $info = @getimagesize($tmp);
            if ($info && !empty($info['mime']) && $info['mime'] !== 'application/octet-stream') {
                return $info['mime'];
            }
        }
        return '';
    }
}

/**
 * MIME → 存储扩展名（白名单反向映射）
 *
 * 落盘用的扩展名必须由这里产出，不能取自用户上传的文件名 —— 见 save_upload_one() 里的说明。
 * 认不出的类型一律落到 .bin：浏览器不认识 .bin 就不会当脚本执行，
 * 而下载链路（stream_document）本来就用 Content-Disposition 强制下载，不影响正常取用。
 */
if (!function_exists('mime_to_ext')) {
    function mime_to_ext($mime)
    {
        static $map = [
            'image/jpeg'      => 'jpg',
            'image/pjpeg'     => 'jpg',
            'image/png'       => 'png',
            'image/gif'       => 'gif',
            'image/webp'      => 'webp',
            'image/avif'      => 'avif',
            'image/heic'      => 'heic',
            'image/heif'      => 'heic',
            'image/bmp'       => 'bmp',
            'image/tiff'      => 'tiff',
            'image/svg+xml'   => 'bin',      // SVG 可内嵌脚本，不存原扩展
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'    => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'          => 'xlsx',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation'  => 'pptx',
            'text/plain'      => 'txt',
            'text/csv'        => 'csv',
        ];
        return isset($map[$mime]) ? $map[$mime] : 'bin';
    }
}

/**
 * documents 表的实际列名集合（一次查询 + 静态缓存）。
 * 返回 null 表示探测不了（权限/异常）——调用方应退回完整列清单，不要因此少写关键字段。
 */
if (!function_exists('documents_columns')) {
    function documents_columns($refresh = false)
    {
        static $cols = false;
        if ($cols === false || $refresh) {
            $cols = null;
            try {
                $rows = db_all('SHOW COLUMNS FROM `documents`');
                if (is_array($rows)) {
                    $names = [];
                    foreach ($rows as $r) {
                        if (isset($r['Field'])) $names[$r['Field']] = true;
                        elseif (isset($r[0]))   $names[$r[0]] = true;
                    }
                    if ($names) $cols = $names;
                }
            } catch (Throwable $e) {
                app_log('documents_columns probe failed: ' . $e->getMessage(), 'WARN');
                $cols = null;
            }
        }
        return $cols;
    }
}

/**
 * UPLOAD_ERR_* → 能指明方向的人话。
 *
 * 为什么必须有这个函数：文件被 PHP 拒收时，$_FILES 里只剩一个错误码，
 * tmp_name 是空字符串。以前代码只看 tmp_name，于是「服务器限额是 2M 还是 50M」
 * 这条唯一的线索被一起丢掉了，前端永远只显示一句 'no file'，无法定位。
 * 错误码 + 服务端实际限额必须一起报出来，运维才知道该改哪里。
 */
if (!function_exists('upload_err_text')) {
    function upload_err_text($code)
    {
        switch ((int)$code) {
            case UPLOAD_ERR_INI_SIZE:
                return 'file exceeds the server limit — upload_max_filesize=' . ini_get('upload_max_filesize')
                     . ', post_max_size=' . ini_get('post_max_size');
            case UPLOAD_ERR_FORM_SIZE:
                return 'file exceeds the form MAX_FILE_SIZE limit';
            case UPLOAD_ERR_PARTIAL:
                return 'upload was interrupted (only part of the file arrived)';
            case UPLOAD_ERR_NO_FILE:
                return 'no file';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'server has no writable temp directory (upload_tmp_dir=' . (ini_get('upload_tmp_dir') ?: '/tmp') . ')';
            case UPLOAD_ERR_CANT_WRITE:
                return 'server could not write the temp file (disk full or temp dir not writable)';
            case UPLOAD_ERR_EXTENSION:
                return 'a PHP extension aborted the upload (check for a security/antivirus extension)';
            default:
                return 'unknown upload error';
        }
    }
}

/**
 * 把 $_FILES 里某一个字段规整成「单文件数组」的列表。
 * 表单用 <input type="file" name="x"> 时是一维；用 name="x[]" multiple 时是二维。
 * 统一成列表，调用方就不用关心表单怎么写的。
 */
if (!function_exists('normalize_files')) {
    function normalize_files($field)
    {
        if (empty($_FILES[$field])) return [];
        $f = $_FILES[$field];
        if (!isset($f['tmp_name'])) return [];
        if (!is_array($f['tmp_name'])) return [$f];

        $out = [];
        $n   = count($f['tmp_name']);
        for ($i = 0; $i < $n; $i++) {
            $err = (int)($f['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            $tmp = (string)($f['tmp_name'][$i] ?? '');
            /*
             * 只有「确实一个都没选」才是空记录，才跳过。
             * 文件被服务器拒收（UPLOAD_ERR_INI_SIZE / FORM_SIZE / NO_TMP_DIR / CANT_WRITE /
             * EXTENSION）时 tmp_name 同样是空字符串 —— 但那是**失败**，不是空记录。
             * 2026-10-06 修：以前只判 tmp_name，把这些失败一并当空记录丢掉，
             * 前端只能看到 'no file'，连「服务器限额是多少」都问不出来。
             */
            if ($tmp === '' && $err === UPLOAD_ERR_NO_FILE) continue;
            $out[] = [
                'name'     => $f['name'][$i]     ?? '',
                'type'     => $f['type'][$i]     ?? '',
                'tmp_name' => $tmp,
                'error'    => $err,
                'size'     => $f['size'][$i]     ?? 0,
            ];
        }
        return $out;
    }
}

/**
 * 保存**一个**已规整的文件数组。
 *
 * @param array  $f          单文件数组（name/tmp_name/error/size）
 * @param array  $extra      追加字段：event_id / title / note / visible
 * @return array ['ok'=>bool,'id'=>int,'error'=>string]
 */
if (!function_exists('save_upload_one')) {
    function save_upload_one(array $f, $ownerType, $ownerId, $kind = 'other', $sensitive = 0, $uploadedBy = 'client', array $extra = [])
    {
        /*
         * 顺序要紧：先看 PHP 的错误码，再看 tmp_name。
         * 反过来的话，被服务器拒收的文件（错误码非 0、tmp_name 为空）会被
         * 一律报成 'no file'，真因（超限 / 临时目录 / 扩展中止）全部丢失。
         * 2026-10-06 修。
         *
         * $verbose 只对管理员开启：把「实际加载的 php.ini 路径」一并报出来，
         * 否则「我明明改成 50M 了」这种事没法当场定位（多半是改错了版本那份 ini）。
         * 前台访客（$uploadedBy='client'）不暴露服务器路径。
         */
        $verbose = ((string)$uploadedBy === 'admin');
        if ((int)($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'id' => 0, 'error' => upload_err_text($f['error'], $verbose)];
        }
        if (empty($f['tmp_name'])) {
            return ['ok' => false, 'id' => 0, 'error' => 'no file'];
        }
        /*
         * 两层限额是两回事：这一层是**应用自设**的 storage.max_upload_mb（app/config.php），
         * 上一层是 PHP 的 upload_max_filesize。改了 php.ini 不动这里，超 16M 依然报错；
         * 反过来只改这里不改 php.ini，则以 PHP 的为准（文件根本进不来）。
         */
        $maxMb = (int)cfg('storage.max_upload_mb', 8);
        if (!empty($f['size']) && $f['size'] > $maxMb * 1024 * 1024) {
            return ['ok' => false, 'id' => 0, 'error' => 'file too large — got '
                . round($f['size'] / 1048576, 1) . 'MB, app limit ' . $maxMb . 'MB'
                . ($verbose ? ' (storage.max_upload_mb in app/config.php — separate from the PHP limits)' : '')];
        }
        $mime = detect_mime($f['tmp_name']);
        $allow = cfg('storage.allowed_mime', []);
        if (!in_array($mime, $allow, true)) {
            // finfo 认不出（旧 libmagic 遇 AVIF/HEIC/Word 返回 octet-stream）时用文件头再判一次
            $alt = detect_mime_fallback($f['tmp_name'], (string)$f['name']);
            if ($alt !== '' && in_array($alt, $allow, true)) {
                $mime = $alt;
            } else {
                $nameHint = $f['name'] !== '' ? ' (file: ' . substr((string)$f['name'], 0, 80) . ')' : '';
                return ['ok' => false, 'id' => 0, 'error' => 'unsupported file type: ' . $mime . $nameHint];
            }
        }

        $dir = storage_path();
        if (!is_dir($dir)) {
            return ['ok' => false, 'id' => 0, 'error' => 'storage dir not writable'];
        }
        /* 扩展名由 MIME 反查，绝不取自用户文件名（2026-09-29 修）
         * 旧写法是 pathinfo($f['name']) 后只过滤 [^a-z0-9] —— 等于没有白名单，
         * .php / .phtml 原样通过。而 MIME 检测只看文件头魔数，
         * 造一个 "GIF89a...<?php system($_GET['c']); ?>" 就能让它判定为 image/gif。
         * 文件落在 web 根内的 app/storage/uploads/，一旦服务器是 Nginx
         * （不认 .htaccess，会把 .php 交给 PHP-FPM），就是一条完整的 getshell 链路。
         * 现在改成：MIME → 固定扩展名映射，映射不到就用安全的 .bin。 */
        $ext = mime_to_ext($mime);
        $stored = date('Ymd') . '_' . bin2hex(random_bytes(12)) . '.' . $ext;
        $dest = $dir . DIRECTORY_SEPARATOR . $stored;

        if (!move_uploaded_file($f['tmp_name'], $dest)) {
            return ['ok' => false, 'id' => 0, 'error' => 'move failed'];
        }
        @chmod($dest, 0640);

        /*
         * 入库。
         *
         * 关键：走到这一步时文件已经落盘了。如果入库失败却什么都不做，磁盘上会留下一个
         * 「没有任何记录指向它」的孤儿文件 —— 后台能看见图，客户却收到「上传失败」，两边对不上。
         * 这正是 2026-09-29 那个 bug 的表现。所以入库失败必须：① 删掉文件 ② 把真实原因回传。
         *
         * 两层防御：
         *   1) 动态拼列 —— 线上库若按旧版 schema 建的，可能没有后加的列，硬写全列会 Unknown column。
         *   2) 表不存在就地补建 —— CREATE TABLE IF NOT EXISTS 是幂等的，建完重试一次。
         */
        $build = function () use ($ownerType, $ownerId, $kind, $stored, $f, $mime, $sensitive, $uploadedBy, $extra) {
            $known = documents_columns();     // null = 探测不了，退回全列
            $cols  = ['owner_type' => $ownerType, 'owner_id' => (int)$ownerId, 'kind' => $kind];
            $maybe = [
                'path'          => $stored,
                'stored_name'   => $stored,
                'original_name' => substr((string)$f['name'], 0, 190),
                'mime'          => $mime,
                'size'          => (int)$f['size'],
                'sensitive'     => $sensitive ? 1 : 0,
                'uploaded_by'   => $uploadedBy,
                // 003 迁移后才有的列：挂到进度节点、给客户看的标题、客户是否可见
                'event_id'      => isset($extra['event_id']) ? (int)$extra['event_id'] : null,
                'title'         => isset($extra['title']) ? mb_substr((string)$extra['title'], 0, 255) : '',
                'note'          => isset($extra['note']) ? (string)$extra['note'] : '',
                'visible'       => isset($extra['visible']) ? ((int)$extra['visible'] ? 1 : 0) : 1,
            ];
            foreach ($maybe as $c => $v) {
                if ($known === null || isset($known[$c])) $cols[$c] = $v;
            }
            /*
             * 列名一律加反引号。
             * 踩过的坑：`sensitive` 是 MySQL 保留字（SQL 标准里游标声明用 INSENSITIVE/SENSITIVE），
             * 裸写会直接 1064 语法错误 —— 而且是从上线第一天就错，只是没人发现。
             */
            $names = [];
            foreach (array_keys($cols) as $c) $names[] = '`' . str_replace('`', '', (string)$c) . '`';
            $sql = 'INSERT INTO documents (' . implode(',', $names) . ') VALUES ('
                 . implode(',', array_fill(0, count($cols), '?')) . ')';
            return (int)db_exec($sql, array_values($cols));
        };

        try {
            $id = $build();
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            $missingTable = (strpos($msg, '1146') !== false) || (stripos($msg, "doesn't exist") !== false);
            if ($missingTable) {
                try {
                    db_exec("CREATE TABLE IF NOT EXISTS `documents` (
                        `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        `owner_type`    ENUM('enquiry','order','client') NOT NULL DEFAULT 'enquiry',
                        `owner_id`      INT UNSIGNED NOT NULL DEFAULT 0,
                        `kind`          ENUM('device','poa','id_doc','notice','certificate','other') NOT NULL DEFAULT 'other',
                        `event_id`      INT UNSIGNED DEFAULT NULL,
                        `title`         VARCHAR(255) NOT NULL DEFAULT '',
                        `note`          TEXT NULL,
                        `visible`       TINYINT(1) NOT NULL DEFAULT 1,
                        `path`          VARCHAR(255) NOT NULL,
                        `stored_name`   VARCHAR(190) NOT NULL DEFAULT '',
                        `original_name` VARCHAR(190) NOT NULL DEFAULT '',
                        `mime`          VARCHAR(100) NOT NULL DEFAULT '',
                        `size`          INT UNSIGNED NOT NULL DEFAULT 0,
                        `sensitive`     TINYINT(1) NOT NULL DEFAULT 0,
                        `uploaded_by`   ENUM('client','admin','system') NOT NULL DEFAULT 'client',
                        `deleted_at`    DATETIME DEFAULT NULL,
                        `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (`id`),
                        KEY `ix_doc_owner` (`owner_type`, `owner_id`),
                        KEY `ix_doc_event` (`event_id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                    app_log('save_upload: auto-created missing `documents` table', 'WARN');
                    documents_columns(true);
                    $id = $build();
                } catch (Throwable $e2) {
                    if (is_file($dest)) @unlink($dest);
                    app_log('save_upload db write failed (after create): ' . $e2->getMessage(), 'ERROR');
                    return ['ok' => false, 'id' => 0, 'error' => 'db write failed: ' . $e2->getMessage()];
                }
            } else {
                if (is_file($dest)) @unlink($dest);
                app_log('save_upload db write failed: ' . $msg, 'ERROR');
                return ['ok' => false, 'id' => 0, 'error' => 'db write failed: ' . $msg];
            }
        }

        return ['ok' => true, 'id' => $id, 'error' => ''];
    }
}

/**
 * 保存表单字段里的**第一个**文件（保持与旧版一致的返回结构，老调用点不用改）。
 * @return array ['ok'=>bool,'id'=>int,'error'=>string]
 */
if (!function_exists('save_upload')) {
    function save_upload($field, $ownerType, $ownerId, $kind = 'other', $sensitive = 0, $uploadedBy = 'client', array $extra = [])
    {
        $list = normalize_files($field);
        if (!$list) return ['ok' => false, 'id' => 0, 'error' => 'no file'];
        return save_upload_one($list[0], $ownerType, $ownerId, $kind, $sensitive, $uploadedBy, $extra);
    }
}

/**
 * 保存表单字段里的**全部**文件（支持 <input type="file" multiple>）。
 * 逐个独立处理：某一个失败不影响其它文件，失败原因逐条回传，不静默吞掉。
 *
 * @return array ['ok'=>bool,'ids'=>int[],'errors'=>string[],'error'=>string]
 */
if (!function_exists('save_uploads')) {
    function save_uploads($field, $ownerType, $ownerId, $kind = 'other', $sensitive = 0, $uploadedBy = 'client', array $extra = [])
    {
        $list = normalize_files($field);
        if (!$list) return ['ok' => false, 'ids' => [], 'errors' => ['no file'], 'error' => 'no file'];

        $ids = []; $errors = [];
        foreach ($list as $i => $f) {
            $r = save_upload_one($f, $ownerType, $ownerId, $kind, $sensitive, $uploadedBy, $extra);
            if ($r['ok']) {
                $ids[] = (int)$r['id'];
            } else {
                $errors[] = ($f['name'] ? $f['name'] . ': ' : '#'.($i+1).': ') . $r['error'];
            }
        }
        return [
            'ok'     => count($ids) > 0,
            'ids'    => $ids,
            'errors' => $errors,
            'error'  => $errors ? implode(' | ', $errors) : '',
        ];
    }
}

/**
 * 直接保存一段已知的二进制内容（不走 $_FILES / move_uploaded_file）。
 *
 * 用途：服务端合成出来的文件（如本合同「客户签名 + 公司印章」合成图）。
 * 内容已经在内存里，没必要先写临时文件再 move —— 直接 file_put_contents 落盘，
 * 然后走与 save_upload_one 完全一致的入库逻辑（随机文件名、动态拼列防 Unknown column、
 * 表缺失就地补建）。落盘目录同样是 app/storage（.htaccess 禁止 web 直连），
 * 因此合成后的印章只通过鉴权页（contract.php 内联 / 后台 file.php）出现，绝不作为公开静态资源。
 *
 * @return array ['ok'=>bool,'id'=>int,'error'=>string]
 */
if (!function_exists('save_document_blob')) {
    function save_document_blob($ownerType, $ownerId, $mime, $binary, $kind = 'other', $sensitive = 0, $uploadedBy = 'client', array $extra = [])
    {
        $dir = storage_path();
        if (!is_dir($dir)) return ['ok' => false, 'id' => 0, 'error' => 'storage dir not writable'];
        if (!is_string($binary) || $binary === '') return ['ok' => false, 'id' => 0, 'error' => 'empty content'];

        $ext    = mime_to_ext($mime);
        $stored = date('Ymd') . '_' . bin2hex(random_bytes(12)) . '.' . $ext;
        $dest   = $dir . DIRECTORY_SEPARATOR . $stored;
        if (file_put_contents($dest, $binary) === false) return ['ok' => false, 'id' => 0, 'error' => 'write failed'];
        @chmod($dest, 0640);

        $build = function () use ($ownerType, $ownerId, $kind, $stored, $mime, $binary, $sensitive, $uploadedBy, $extra) {
            $known = documents_columns();
            $cols  = ['owner_type' => $ownerType, 'owner_id' => (int)$ownerId, 'kind' => $kind];
            $maybe = [
                'path'          => $stored,
                'stored_name'   => $stored,
                'original_name' => isset($extra['original_name']) ? mb_substr((string)$extra['original_name'], 0, 190) : '',
                'mime'          => $mime,
                'size'          => (int)strlen($binary),
                'sensitive'     => $sensitive ? 1 : 0,
                'uploaded_by'   => $uploadedBy,
                'event_id'      => isset($extra['event_id']) ? (int)$extra['event_id'] : null,
                'title'         => isset($extra['title']) ? mb_substr((string)$extra['title'], 0, 255) : '',
                'note'          => isset($extra['note']) ? (string)$extra['note'] : '',
                'visible'       => isset($extra['visible']) ? ((int)$extra['visible'] ? 1 : 0) : 1,
            ];
            foreach ($maybe as $c => $v) {
                if ($known === null || isset($known[$c])) $cols[$c] = $v;
            }
            $names = [];
            foreach (array_keys($cols) as $c) $names[] = '`' . str_replace('`', '', (string)$c) . '`';
            $sql = 'INSERT INTO documents (' . implode(',', $names) . ') VALUES ('
                 . implode(',', array_fill(0, count($cols), '?')) . ')';
            return (int)db_exec($sql, array_values($cols));
        };

        try {
            $id = $build();
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            $missingTable = (strpos($msg, '1146') !== false) || (stripos($msg, "doesn't exist") !== false);
            if ($missingTable) {
                try {
                    db_exec("CREATE TABLE IF NOT EXISTS `documents` (
                        `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        `owner_type`    ENUM('enquiry','order','client') NOT NULL DEFAULT 'enquiry',
                        `owner_id`      INT UNSIGNED NOT NULL DEFAULT 0,
                        `kind`          ENUM('device','poa','id_doc','notice','certificate','other') NOT NULL DEFAULT 'other',
                        `event_id`      INT UNSIGNED DEFAULT NULL,
                        `title`         VARCHAR(255) NOT NULL DEFAULT '',
                        `note`          TEXT NULL,
                        `visible`       TINYINT(1) NOT NULL DEFAULT 1,
                        `path`          VARCHAR(255) NOT NULL,
                        `stored_name`   VARCHAR(190) NOT NULL DEFAULT '',
                        `original_name` VARCHAR(190) NOT NULL DEFAULT '',
                        `mime`          VARCHAR(100) NOT NULL DEFAULT '',
                        `size`          INT UNSIGNED NOT NULL DEFAULT 0,
                        `sensitive`     TINYINT(1) NOT NULL DEFAULT 0,
                        `uploaded_by`   ENUM('client','admin','system') NOT NULL DEFAULT 'client',
                        `deleted_at`    DATETIME DEFAULT NULL,
                        `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (`id`),
                        KEY `ix_doc_owner` (`owner_type`, `owner_id`),
                        KEY `ix_doc_event` (`event_id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                    app_log('save_document_blob: auto-created missing `documents` table', 'WARN');
                    documents_columns(true);
                    $id = $build();
                } catch (Throwable $e2) {
                    if (is_file($dest)) @unlink($dest);
                    app_log('save_document_blob db write failed (after create): ' . $e2->getMessage(), 'ERROR');
                    return ['ok' => false, 'id' => 0, 'error' => 'db write failed: ' . $e2->getMessage()];
                }
            } else {
                if (is_file($dest)) @unlink($dest);
                app_log('save_document_blob db write failed: ' . $msg, 'ERROR');
                return ['ok' => false, 'id' => 0, 'error' => 'db write failed: ' . $msg];
            }
        }

        return ['ok' => true, 'id' => (int)$id, 'error' => ''];
    }
}

/**
 * 软删除附件。
 * 法律业务的记录不做物理删除：文件留在磁盘、行留在库里，只是不出现在列表上，
 * 将来客户来问「当初那份受理通知书呢」还能找回来。
 */
if (!function_exists('doc_soft_delete')) {
    function doc_soft_delete($docId)
    {
        if (!table_has_column('documents', 'deleted_at')) {
            // 迁移没跑：退回物理删除，总比「点了删除没反应」强
            db_exec('DELETE FROM documents WHERE id = ?', [(int)$docId]);
            return true;
        }
        db_exec('UPDATE `documents` SET `deleted_at` = NOW() WHERE id = ?', [(int)$docId]);
        return true;
    }
}

if (!function_exists('doc_restore')) {
    function doc_restore($docId)
    {
        if (!table_has_column('documents', 'deleted_at')) return false;
        db_exec('UPDATE `documents` SET `deleted_at` = NULL WHERE id = ?', [(int)$docId]);
        return true;
    }
}

/**
 * 取某 owner 下的附件。
 * 没跑 003 迁移时（无 visible / deleted_at 列）自动降级为「全部可见」，不会查崩。
 *
 * @param array $opts ['include_hidden'=>bool, 'include_deleted'=>bool, 'event_id'=>int|null]
 */
if (!function_exists('doc_list')) {
    function doc_list($ownerType, $ownerId, array $opts = [])
    {
        $includeHidden  = !empty($opts['include_hidden']);
        $includeDeleted = !empty($opts['include_deleted']);

        $where = ['`owner_type` = ?', '`owner_id` = ?'];
        $args  = [$ownerType, (int)$ownerId];

        // 迁移未跑时这两列不存在，条件必须整条跳过，否则 Unknown column 直接 500
        if (!$includeDeleted && table_has_column('documents', 'deleted_at')) {
            $where[] = '`deleted_at` IS NULL';
        }
        if (!$includeHidden && table_has_column('documents', 'visible')) {
            $where[] = '`visible` = 1';
        }
        if (array_key_exists('event_id', $opts) && table_has_column('documents', 'event_id')) {
            if ($opts['event_id'] === null) $where[] = '`event_id` IS NULL';
            else { $where[] = '`event_id` = ?'; $args[] = (int)$opts['event_id']; }
        }

        $sql = 'SELECT * FROM `documents` WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC';
        try {
            return db_all($sql, $args);
        } catch (Throwable $e) {
            app_log('doc_list failed: ' . $e->getMessage(), 'ERROR');
            return [];
        }
    }
}

/**
 * 给附件改标题 / 备注 / 客户可见性（后台逐条编辑）。
 * 只更新实际存在的列，迁移没跑也不会报错。
 */
if (!function_exists('doc_update_meta')) {
    function doc_update_meta($docId, array $meta)
    {
        $sets = []; $args = [];
        $map = [
            'title'   => table_has_column('documents', 'title'),
            'note'    => table_has_column('documents', 'note'),
            'visible' => table_has_column('documents', 'visible'),
            'kind'    => true,
        ];
        foreach ($map as $col => $exists) {
            if (!$exists || !array_key_exists($col, $meta)) continue;
            $sets[] = '`' . $col . '` = ?';
            $args[] = ($col === 'visible') ? ((int)$meta[$col] ? 1 : 0) : $meta[$col];
        }
        if (!$sets) return false;
        $args[] = (int)$docId;
        db_exec('UPDATE `documents` SET ' . implode(',', $sets) . ' WHERE id = ?', $args);
        return true;
    }
}

/**
 * OLE2 / ZIP 容器内部的细分：按扩展名提示挑一个 Office MIME。
 * 只在魔数已确认容器类型之后才调用，所以扩展名无法把别的东西伪装成 Office 文件。
 */
if (!function_exists('mime_from_office_hint')) {
    function mime_from_office_hint($nameHint, $defaultExt)
    {
        static $map = [
            'doc'  => 'application/msword',
            'dot'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls'  => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt'  => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ];
        $ext = strtolower(pathinfo((string)$nameHint, PATHINFO_EXTENSION));
        if ($ext !== '' && isset($map[$ext])) return $map[$ext];
        return isset($map[$defaultExt]) ? $map[$defaultExt] : '';
    }
}

/** 记录敏感文件访问 */
if (!function_exists('log_doc_access')) {
    function log_doc_access($docId, $actorType, $actorId)
    {
        try {
            db_exec('INSERT INTO document_access_log (document_id,actor_type,actor_id,ip) VALUES (?,?,?,?)',
                [(int)$docId, $actorType, (int)$actorId, client_ip()]);
        } catch (Exception $e) {
            app_log('log_doc_access failed: ' . $e->getMessage(), 'ERROR');
        }
    }
}

/**
 * 输出文件（调用方必须先鉴权）
 * @param bool $inline true = 浏览器内嵌显示（仅图片），false = 附件下载
 */
if (!function_exists('stream_document')) {
    function stream_document($doc, $actorType, $actorId, $inline = false)
    {
        $dir = storage_path();
        $file = $dir . DIRECTORY_SEPARATOR . basename($doc['stored_name']);
        if (!is_file($file)) abort404('File not found');

        if (!empty($doc['sensitive'])) log_doc_access($doc['id'], $actorType, $actorId);

        $name = rawurlencode($doc['original_name'] ?: 'file');
        header('Content-Description: File Transfer');
        header('Content-Type: ' . ($doc['mime'] ?: 'application/octet-stream'));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"');
        header('Content-Length: ' . (int)@filesize($file));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($file);
        exit;
    }
}
