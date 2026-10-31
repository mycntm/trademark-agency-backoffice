<?php
/**
 * 后台受控文件下载（不走 web 直连）
 * 敏感文件（委托书 / 身份证件）需 owner 或 can_view_sensitive 权限，且访问写日志。
 */
require_once __DIR__ . '/../bootstrap.php';
require_admin();

$id  = (int)get('id', 0);
$doc = db_one('SELECT * FROM documents WHERE id = ?', [$id]);
if (!$doc) abort404('File not found');

$canSensitive = admin_can_sensitive();
if (!empty($doc['sensitive']) && !$canSensitive) {
    http_response_code(403);
    echo lang() === 'zh' ? '无权限查看该敏感文件。' : 'You do not have permission to view this sensitive file.';
    exit;
}

/* inline=1 仅用于后台列表/详情页内嵌预览；只对位图开放，杜绝 HTML/SVG 类内容被当页渲染 */
$wantInline = ((string)get('inline', '0') === '1');
$inline     = $wantInline
    && strpos((string)$doc['mime'], 'image/') === 0
    && !in_array(strtolower((string)$doc['mime']), ['image/svg+xml', 'image/svg'], true);

stream_document($doc, 'admin', admin_id(), $inline);
