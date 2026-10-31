<?php
/**
 * 站内留言总览（客户 <-> 我们）
 *
 * 背景：客户在 account.php 订单页发的留言，后台原来完全没有入口，
 * 只能翻数据库才看得到。这里做统一收件箱：
 *   - 订单留言 + 询价留言都在这里
 *   - 默认把「客户发了、我们还没回」的会话排最前（待回复）
 *   - 点「打开」跳到对应工单/订单详情页去回复
 */
require_once __DIR__ . '/_layout.php';

$onlyOpen = (get('open', '0') === '1');

/* 哪些会话在等我们回复：该会话最后一条是客户发的 */
$openOrders = [];
$openEnqs   = [];
try {
    foreach (db_all("SELECT m.order_id AS tid FROM messages m
                     JOIN (SELECT order_id AS tid, MAX(id) AS last_id FROM messages
                           WHERE order_id IS NOT NULL GROUP BY order_id) t
                       ON t.tid = m.order_id AND t.last_id = m.id
                     WHERE m.sender_role = 'client'") as $r) {
        $openOrders[(int)$r['tid']] = true;
    }
    foreach (db_all("SELECT m.enquiry_id AS tid FROM messages m
                     JOIN (SELECT enquiry_id AS tid, MAX(id) AS last_id FROM messages
                           WHERE enquiry_id IS NOT NULL GROUP BY enquiry_id) t
                       ON t.tid = m.enquiry_id AND t.last_id = m.id
                     WHERE m.sender_role = 'client'") as $r) {
        $openEnqs[(int)$r['tid']] = true;
    }
} catch (Throwable $e) {
    app_log('messages.php open-thread probe failed: ' . $e->getMessage(), 'WARN');
}

$rows = db_all("SELECT m.*, o.ref AS order_ref, o.id AS oid,
                       e.ref AS enquiry_ref, e.id AS eid,
                       c.name AS client_name, c.email AS client_email
                FROM messages m
                LEFT JOIN orders o    ON o.id = m.order_id
                LEFT JOIN enquiries e ON e.id = m.enquiry_id
                LEFT JOIN clients c   ON c.id = COALESCE(o.client_id, e.client_id)
                ORDER BY m.id DESC
                LIMIT 300");

/* 排序：待回复的会话置顶（按会话最新一条时间倒序），其余按时间倒序 */
$threadKey = function ($r) { return $r['order_id'] ? 'o' . $r['order_id'] : 'e' . $r['enquiry_id']; };
$isOpen    = function ($r) use ($openOrders, $openEnqs) {
    return $r['order_id'] ? isset($openOrders[(int)$r['order_id']]) : isset($openEnqs[(int)$r['enquiry_id']]);
};

$view = [];
foreach ($rows as $r) {
    if (!$r['order_id'] && !$r['enquiry_id']) continue;   // 游离消息（无归属）不进收件箱
    $k = $threadKey($r);
    if (!isset($view[$k])) {
        $view[$k] = ['open' => $isOpen($r), 'last' => $r, 'items' => []];
    }
    $view[$k]['items'][] = $r;
}
uasort($view, function ($a, $b) {
    if ($a['open'] !== $b['open']) return $a['open'] ? -1 : 1;
    return strcmp($b['last']['created_at'], $a['last']['created_at']);
});

admin_header('adm.messages', 'messages');
?>
<div class="ad-card">
    <h2>
        Inbox
        <span class="lg-sm" style="font-weight:400;opacity:.7;margin-left:6px;">
            <?php echo count($view); ?> conversations
        </span>
    </h2>
    <p style="margin:-4px 0 14px;">
        <a class="btn btn-s <?php echo !$onlyOpen ? 'btn-p' : ''; ?>" href="<?php echo h(site_url('app/admin/messages.php')); ?>"><?php echo lang() === 'zh' ? '全部' : 'All'; ?></a>
        <a class="btn btn-s <?php echo $onlyOpen ? 'btn-p' : ''; ?>" href="<?php echo h(site_url('app/admin/messages.php?open=1')); ?>">
            <?php echo lang() === 'zh' ? '只看待回复' : 'Awaiting reply'; ?> (<?php echo count(array_filter($view, function ($v) { return $v['open']; })); ?>)
        </a>
    </p>

    <?php foreach ($view as $v):
        $last = $v['last'];
        if ($onlyOpen && !$v['open']) continue;
        $url  = $last['order_id']  ? site_url('app/admin/order.php?id=' . $last['order_id'])
              : ($last['enquiry_id'] ? site_url('app/admin/enquiry.php?id=' . $last['enquiry_id']) : '');
        $ref  = $last['order_ref'] ?: ($last['enquiry_ref'] ?: '—');
        $who  = $last['client_name'] ?: ($last['client_email'] ?: '—');
    ?>
    <div style="border:1px solid <?php echo $v['open'] ? '#fecaca' : 'var(--bd)'; ?>;border-radius:9px;padding:13px 15px;margin-bottom:11px;background:<?php echo $v['open'] ? '#fffdf7' : '#fff'; ?>;">
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:8px;">
            <span class="ad-mono"><?php echo h($ref); ?></span>
            <?php if ($v['open']): ?><span class="ad-tag new"><?php echo lang() === 'zh' ? '待回复' : 'Awaiting reply'; ?></span><?php endif; ?>
            <b><?php echo h($who); ?></b>
            <span class="lg-sm" style="opacity:.65;"><?php echo h($last['client_email']); ?></span>
            <span class="lg-sm" style="margin-left:auto;opacity:.6;"><?php echo h($last['created_at']); ?></span>
            <?php if ($url): ?><a class="btn btn-s" href="<?php echo h($url); ?>"><?php echo lang() === 'zh' ? '打开回复' : 'Open'; ?></a><?php endif; ?>
        </div>
        <div style="font-size:13.5px;color:#1f2937;white-space:pre-wrap;border-left:3px solid var(--bd);padding-left:11px;">
            <?php echo h(mb_substr((string)$last['body'], 0, 400)); ?>
        </div>
        <?php $n = count($v['items']); if ($n > 1): ?>
            <div class="lg-sm" style="opacity:.6;margin-top:7px;"><?php echo $n; ?> <?php echo lang() === 'zh' ? '条往来消息' : 'messages in this conversation'; ?></div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <?php if (!$view): ?><div class="ad-empty"><?php echo h(t('adm.no_data')); ?></div><?php endif; ?>
</div>
<?php admin_footer(); ?>
