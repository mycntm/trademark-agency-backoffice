<?php
/**
 * 订单详情：四项要素 / 进度时间线 / 付款 / 文件
 */
require_once __DIR__ . '/_layout.php';

$id = (int)get('id', 0);
$o  = db_one('SELECT * FROM orders WHERE id = ?', [$id]);
if (!$o) abort404('Order not found');
$client = db_one('SELECT * FROM clients WHERE id = ?', [$o['client_id']]);

$msg = ''; $err = '';

/* 把 classes_json 渲染成「类号一行 + 商品一条一行」的纯文本。
 * 订单页两处共用同一个函数，保证展示与编辑框永远同一份数据（2026-10-17）：
 *   ① 详情卡上方的 Goods & services 只读展示（原先 implode('; ') 挤成一行，读不了）；
 *   ② 编辑框的默认值（原先恒为空，订单与询价的商品数据不互通，得照着重敲）。
 * classes_normalize：兼容前台询价（cls 扁平）与手工建单（class 嵌套）两种结构。
 * 中英并列（最终递交 CNIPA 以中文为准），不属全站英文化范围。 */
if (!function_exists('order_classes_text')) {
    function order_classes_text($classesJson)
    {
        $out = '';
        foreach (classes_normalize($classesJson) as $cls => $its) {
            if ($out !== '') $out .= "\n";
            $out .= 'Class ' . (int)$cls;
            $lastGroup = '';
            foreach ($its as $it) {
                if ($it['grp'] !== '' && $it['grp'] !== $lastGroup) {
                    $out .= "\n  Similar-goods group " . $it['grp'] . ($it['grp_name'] !== '' ? ' · ' . $it['grp_name'] : '');
                    $lastGroup = $it['grp'];
                }
                $line = $it['name'] !== '' && $it['en'] !== '' ? $it['name'] . ' / ' . $it['en'] : ($it['name'] !== '' ? $it['name'] : $it['en']);
                if ($line !== '') $out .= "\n    " . ($it['code'] !== '' ? $it['code'] . ' · ' : '') . $line;
            }
        }
        return $out;
    }
}

/* ---------- 更新状态 / 订单内容 / 报价 ---------- */
if (is_post() && post('act') === 'update') {
    if (!csrf_check()) {
        $err = t('enq.err_csrf');
    } else {
        $st = post('status');
        $allowed = ['pending_payment','paid','filed','examining','published','opposed','registered','refused','withdrawn','refunded'];
        if (in_array($st, $allowed, true)) {
            /* 订单内容建单后必须可改（2026-10-13）
             *
             * 背景：客户中途改需求是常态——本来 9 个商标减成 5 个、名称录错、
             * 重新报价。改不了就只能删单重建，可一单里可能已经有付款记录、
             * 进度节点和客户留言，物理删除会把这些连坐清掉；作废重建又会让客户
             * 看到「两单 + 一条已作废」，解释成本更高。
             *
             * 在此之前本分支只写 status / CNIPA 号 / 注册人，商标名称、类型、
             * 已有注册号、主体类型、类别、总价六个字段建完就是死值。
             *
             * 两个 ENUM 字段走白名单：非法值一律保留原值，不让它把整条 UPDATE
             * 写成脏数据（mark_type / registrant_type 只从 select 来，但保存旧链接、
             * 手工构造 POST 也会送错值）。 */
            $mt = (string)post('mark_type', $o['mark_type']);
            if (!in_array($mt, ['word','device','combined','sound','other'], true)) $mt = (string)$o['mark_type'];
            $rt = (string)post('registrant_type', $o['registrant_type']);
            if (!in_array($rt, ['individual','company','other'], true)) $rt = (string)$o['registrant_type'];

            /* 文本字段一律带默认值取：post() 键缺失时返回 default，
             * 所以「用户开着改版前的旧页面提交」时这些字段保留原值而不是被抹成空串。
             * 真正提交空串（用户故意清空）仍然有效 —— 那是有意为之。 */
            $markName = (string)post('mark_name', (string)$o['mark_name']);
            $existing = (string)post('existing_reg_no', (string)$o['existing_reg_no']);

            $sets = ['`status` = ?', '`cnipa_app_no` = ?', '`registrant_name` = ?', '`registrant_addr` = ?',
                     '`mark_name` = ?', '`existing_reg_no` = ?', '`mark_type` = ?', '`registrant_type` = ?'];
            $args = [$st,
                     trim((string)post('cnipa_app_no')),
                     trim((string)post('registrant_name')),
                     trim((string)post('registrant_addr')),
                     $markName,
                     $existing,
                     $mt,
                     $rt];

            /* 类别：解析规则与建单页一致（只取数字），但**保留仍在新清单里的类别的商品项**。
             * 后台本来就是「一栏多个商标、逗号分隔」的粗粒度用法，改类别最常见的动作是
             * 减一个类别（9 件 → 5 件）。若按建单页那样重建空 items，
             * 从询价带过来的商品名清单会被一起抹平，页面上半部分立刻变空。
             * 键缺失（旧表单没带 classes 字段）时完全不动 classes_json。 */
            $classesRaw = post('classes', null);
            $newNums = [];
            if ($classesRaw !== null) {
                foreach (preg_split('/[,，\s]+/', trim((string)$classesRaw)) as $cn) {
                    $cn = (int)preg_replace('/\D/', '', $cn);
                    if ($cn > 0) $newNums[$cn] = $cn;
                }
            }
            ksort($newNums);
            $oldNums = [];
            $oldRaw  = json_decode((string)$o['classes_json'], true);
            if (is_array($oldRaw)) {
                foreach ($oldRaw as $c) {
                    if (!is_array($c)) continue;
                    $n = (int)preg_replace('/\D/', '', (string)($c['cls'] ?? $c['class'] ?? ''));
                    if ($n > 0) $oldNums[$n] = $n;
                }
            }
            ksort($oldNums);
            if ($classesRaw !== null && $newNums !== $oldNums) {
                $oldItems = classes_normalize($o['classes_json']);   // [类别 => [['name','en']…]]
                $newJson  = [];
                foreach ($newNums as $n) {
                    $newJson[] = ['class' => $n, 'heading' => '', 'items' => $oldItems[$n] ?? []];
                }
                $sets[] = '`classes_json` = ?';
                $args[] = $newJson ? json_encode($newJson, JSON_UNESCAPED_UNICODE) : null;
            }

            /* 总价：改报价不该删单重建。只动 service_fee，官费 / 翻译费保持不动，
             * 这样「官费 + 服务费 + 翻译费 = 总价」这笔账仍然平。
             * 非数字（含空串、旧表单没带这个字段）时保留原总价，绝不抹成 0。
             * 万一降得比官费还狠导致 service_fee 为负，夹到 0 并写日志——
             * 此时三列之和会大于总价，属于要人工复核的异常，不静默吞掉。 */
            $totalRaw  = post('total', null);
            $newTotal  = ($totalRaw !== null && is_numeric((string)$totalRaw)) ? round((float)$totalRaw, 2) : (float)$o['total'];
            if ($newTotal < 0) $newTotal = 0.0;
            $official = (float)($o['official_fee'] ?? 0);
            $trans    = (float)($o['translation_fee'] ?? 0);
            $newSvc   = round($newTotal - $official - $trans, 2);
            if ($newSvc < 0) {
                app_log('order #' . $id . ': total edited below official+translation fees (total='
                      . $newTotal . ', official=' . $official . ', translation=' . $trans
                      . ') — service_fee clamped to 0, needs manual review', 'WARN');
                $newSvc = 0.0;
            }
            $sets[] = '`total` = ?';       $args[] = $newTotal;
            $sets[] = '`service_fee` = ?'; $args[] = $newSvc;

            if (table_has_column('orders', 'cnipa_reg_no')) {
                $sets[] = '`cnipa_reg_no` = ?';   $args[] = trim((string)post('cnipa_reg_no'));
            }
            /* 字段缺失（浏览器缓存了改版前的页面）时整列不动 —— 否则空串会被当成
             * 「显式清空 = 隐藏」，把正当的商品清单误藏掉（与 classes 字段同一处理）。 */
            $gsPostRaw = post('goods_services', null);
            if (table_has_column('orders', 'goods_services') && $gsPostRaw !== null) {
                /* 商品和服务：编辑框预填的是询价类别的渲染结果（order_classes_text）。
                 * 提交值与预填值一模一样 → 管理员没动过这一栏，存 NULL：这张单继续跟随
                 * classes_json，不把展示用的临时值固化下来（否则之后改类别，商品会停在旧值）。
                 * 真改过才落库；提交空串 = 显式隐藏该行（NULL 与 '' 语义分开，2026-10-17）。 */
                $gsPosted = trim((string)$gsPostRaw);
                $gsStored = ($gsPosted === order_classes_text($o['classes_json'])) ? null : $gsPosted;
                $sets[] = '`goods_services` = ?'; $args[] = $gsStored;
            }
            $args[] = $id;
            $before = $o;   // 留一份改前的行，用于审计与「降到了已收款以下」判断
            db_exec('UPDATE orders SET ' . implode(', ', $sets) . ' WHERE id=?', $args);
            if ($st === 'filed' && empty($o['filed_at'])) {
                db_exec('UPDATE orders SET filed_at=NOW() WHERE id=?', [$id]);
            }
            $o = db_one('SELECT * FROM orders WHERE id = ?', [$id]);   // 回读，详情卡立即显示新值
            $msg = t('adm.saved');

            /* 审计：只记订单内容与报价的变更，状态变更不记（避免日志噪音） */
            $changed = [];
            if ((string)$before['mark_name'] !== $markName)            $changed[] = 'mark_name';
            if ((string)$before['existing_reg_no'] !== $existing)      $changed[] = 'existing_reg_no';
            if ((string)$before['mark_type'] !== $mt)                  $changed[] = 'mark_type';
            if ((string)$before['registrant_type'] !== $rt)            $changed[] = 'registrant_type';
            if ($classesRaw !== null && $newNums !== $oldNums)         $changed[] = 'classes';
            if (abs((float)$before['total'] - $newTotal) > 0.001)      $changed[] = 'total';
            if ($changed) {
                admin_log_action('update', 'order', $id, 'edited: ' . implode(',', $changed)
                    . (in_array('total', $changed, true)
                        ? ' (' . (float)$before['total'] . ' -> ' . $newTotal . ' ' . $o['currency'] . ')' : ''));
                /* 降价到已收款以下 = 客户多付了，要么退款要么补个说明。
                   这里只留痕不阻断：有时就是客户补差价、有时确实是该退。 */
                if ((float)$before['paid_amount'] > $newTotal + 0.001) {
                    app_log('order #' . $id . ': total lowered below paid_amount — paid='
                          . (float)$before['paid_amount'] . ' new total=' . $newTotal
                          . ' (refund or a note may be needed)', 'WARN');
                }
            }
        }
    }
}

/* ---------- 添加进度事件 ---------- */
if (is_post() && post('act') === 'event') {
    if (!csrf_check()) {
        $err = t('enq.err_csrf');
    } else {
        $stage = post('stage', 'note');
        $tEn = post('title_en');
        $tZh = post('title_zh');
        $note = post('note');
        $vis = (int)post('visible', 1);
        add_order_event($id, $stage, $tEn, $tZh, $note, $vis);
        /* 勾选才发信：内部补录的节点（比如我们自己记一笔）不该打扰客户（2026-09-29） */
        if ($vis && $client && ad_wants_notify()) {
            mail_order_event($o, $client, $tEn, $tZh, $note);
        }
        $msg = ($vis && $client && !ad_wants_notify()) ? t('adm.notify_skipped') : t('adm.saved');
    }
}

/* ---------- 编辑进度节点 ---------- */
if (is_post() && post('act') === 'event_edit') {
    if (!csrf_check()) {
        $err = t('enq.err_csrf');
    } else {
        $eid = (int)post('event_id', 0);
        $ev  = db_one('SELECT * FROM order_events WHERE id = ? AND order_id = ?', [$eid, $id]);
        if ($ev) {
            update_order_event($eid, [
                'stage'    => post('stage', $ev['stage']),
                'title_en' => trim((string)post('title_en')),
                'title_zh' => trim((string)post('title_zh')),
                'note'     => trim((string)post('note')),
                'visible'  => (int)post('visible', $ev['visible']) ? 1 : 0,
            ]);
            admin_log_action('update', 'order_event', $eid, '');
            $msg = t('adm.saved');
        } else {
            $err = lang() === 'zh' ? '节点不存在。' : 'Event not found.';
        }
    }
}

/* ---------- 删除 / 恢复进度节点（软删除） ---------- */
if (is_post() && post('act') === 'event_del') {
    if (!csrf_check()) {
        $err = t('enq.err_csrf');
    } else {
        $eid = (int)post('event_id', 0);
        $ev  = db_one('SELECT * FROM order_events WHERE id = ? AND order_id = ?', [$eid, $id]);
        if ($ev) {
            if (post('mode') === 'restore') {
                restore_order_event($eid);
                admin_log_action('restore', 'order_event', $eid, '');
            } else {
                delete_order_event($eid);
                admin_log_action('delete', 'order_event', $eid, ($ev['title_zh'] ?: $ev['title_en']));
            }
            $msg = t('adm.saved');
        } else {
            $err = lang() === 'zh' ? '节点不存在。' : 'Event not found.';
        }
    }
}

/* ---------- 标记银行转账收款 ---------- */
if (is_post() && post('act') === 'mark_paid') {
    if (!csrf_check()) {
        $err = t('enq.err_csrf');
    } elseif (!idem_check('mark_paid_' . $id)) {
        // 一次性令牌：挡住「刷新 / 后退再提交 / 连点」造成的重复入账（2026-09-30）
        $err = idem_dup_message();
    } else {
        $amt = (float)post('amount', 0);
        if ($amt > 0) {
            /* 银行转账登记也走 apply_payment()，所以客户一样会收到「付款已收到」；
               不勾「通知客户」则只入账、静默处理（2026-09-29） */
            $notify = ad_wants_notify();
            apply_payment($id, $amt, 'bank_transfer', 'MANUAL-' . date('YmdHis'), '', $notify);
            $msg = $notify ? t('adm.saved') : t('adm.notify_skipped');
        }
    }
}

/* ---------- 审核客户上传的回单 ---------- */
if (is_post() && (post('act') === 'confirm_receipt' || post('act') === 'reject_receipt')) {
    $rid = (int)post('receipt_id');
    $isConfirm = (post('act') === 'confirm_receipt');
    $idemKey = ($isConfirm ? 'confirm_receipt_' : 'reject_receipt_') . $rid;
    if (!csrf_check()) {
        $err = t('enq.err_csrf');
    } elseif (!idem_check($idemKey)) {
        $err = idem_dup_message();
    } else {
        ensure_receipts_table();
        $rec = db_one('SELECT * FROM receipts WHERE id=? AND order_id=?', [$rid, $id]);
        if (!$rec) {
            $err = lang() === 'zh' ? '回单不存在。' : 'Receipt not found.';
        } elseif ($isConfirm) {
            $amt = (float)post('amount', 0);
            if ($amt <= 0) {
                $err = lang() === 'zh' ? '金额必须大于 0。' : 'Amount must be greater than 0.';
            } else {
                /* 确认收款 = 入账 + 发「已收到款」邮件（复用 apply_payment 的统一逻辑）。
                   provider_ref 用 RECEIPT-<id>：同一张回单重复确认会被幂等挡住，不会重复入账。 */
                db_exec('UPDATE receipts SET status="confirmed", admin_id=?, confirmed_at=NOW() WHERE id=?', [admin_id(), $rid]);
                $notify = ad_wants_notify();
                apply_payment($id, $amt, 'bank_transfer', 'RECEIPT-' . $rid, '', $notify);
                admin_log_action('confirm_receipt', 'order', $id, 'receipt #' . $rid . ' amount=' . $amt);
                $msg = $notify ? t('adm.saved') : t('adm.notify_skipped');
            }
        } else {
            $note = trim((string)post('reject_note'));
            db_exec('UPDATE receipts SET status="rejected", note=?, admin_id=? WHERE id=?', [$note, admin_id(), $rid]);
            admin_log_action('reject_receipt', 'order', $id, 'receipt #' . $rid);
            $msg = lang() === 'zh' ? '已驳回该回单。' : 'Receipt rejected.';
        }
    }
}

/* ---------- 作废订单 ---------- */
if (is_post() && post('act') === 'void') {
    if (!csrf_check()) {
        $err = t('enq.err_csrf');
    } else {
        $reason = trim((string)post('void_reason'));
        void_order($id, $reason);
        admin_log_action('void', 'order', $id, $reason);
        $msg = t('adm.void_done');
    }
}

/* ---------- 恢复已作废订单 ---------- */
if (is_post() && post('act') === 'restore_void') {
    if (!csrf_check()) {
        $err = t('enq.err_csrf');
    } else {
        $restoredStatus = restore_voided_order($id);
        if ($restoredStatus) {
            admin_log_action('restore', 'order', $id, 'restored voided order to ' . $restoredStatus);
            $msg = lang() === 'zh' ? '订单已恢复为“' . order_status_label($restoredStatus) . '”。' : 'Order restored to ' . order_status_label($restoredStatus) . '.';
        } else {
            $err = lang() === 'zh' ? '订单未作废或恢复失败。' : 'Order is not voided or could not be restored.';
        }
    }
}

/* ---------- 彻底删除订单（物理删除，不可逆） ---------- */
if (is_post() && post('act') === 'hard_delete') {
    if (!csrf_check()) {
        $err = t('enq.err_csrf');
    } elseif (!admin_can_hard_delete()) {
        $err = lang() === 'zh' ? '权限不足：只读账号不能执行物理删除。' : 'Permission denied: viewer accounts cannot delete records.';
    } elseif (trim((string)post('confirm_text')) !== (string)$o['ref']) {
        // 二次确认：必须原样输入订单号，防止手滑把别的单删了
        $err = lang() === 'zh' ? '确认编号不匹配，删除已取消（需原样输入订单号 ' . $o['ref'] . '）。'
                               : 'Confirmation text does not match — deletion cancelled (type the order ref exactly: ' . $o['ref'] . ').';
    } else {
        $r = hard_delete_order($id);
        if ($r['ok']) {
            // 统计放进 URL 参数，列表页据此回显（本项目用 URL 传提示，没有 session flash）
            $st = $r['stats'];
            redirect(site_url('app/admin/orders.php?hard_deleted=' . rawurlencode($st['ref'])
                . '&ev=' . (int)$st['events'] . '&pay=' . (int)$st['payments']
                . '&msg=' . (int)$st['messages'] . '&doc=' . (int)$st['docs']));
        } else {
            $err = lang() === 'zh' ? '删除失败：' . $r['error'] : 'Delete failed: ' . $r['error'];
        }
    }
}

/* ---------- 上传文件（可多选，可挂到某个进度节点） ---------- */
if (is_post() && post('act') === 'upload') {
    if (!csrf_check()) {
        $err = t('enq.err_csrf');
    } else {
        $kind = post('kind', 'other');
        if (!in_array($kind, ['device','poa','id_doc','notice','certificate','other'], true)) $kind = 'other';
        $sensitive = in_array($kind, ['poa','id_doc'], true) ? 1 : 0;
        $extra = [
            'event_id' => ((int)post('event_id') > 0 ? (int)post('event_id') : null),
            'title'    => trim((string)post('doc_title')),
            'note'     => trim((string)post('doc_note')),
            'visible'  => (int)post('doc_visible', 1) ? 1 : 0,
        ];
        $up = save_uploads('files', 'order', $id, $kind, $sensitive, 'admin', $extra);
        if ($up['ok']) {
            if ($kind === 'certificate') db_exec('UPDATE orders SET status="registered" WHERE id=? AND status IN ("published","examining")', [$id]);
            foreach ($up['ids'] as $did) {
                admin_log_action('upload', 'document', (int)$did, $kind . ($extra['event_id'] ? ' @event ' . $extra['event_id'] : ''));
            }
            $msg = t('adm.saved');
            if ($up['errors']) {
                $msg .= lang() === 'zh'
                    ? '（有文件失败：' . implode('；', $up['errors']) . '）'
                    : ' (some files failed: ' . implode('; ', $up['errors']) . ')';
            }
        } else {
            $err = lang() === 'zh' ? '上传失败：' . $up['error'] : 'Upload failed: ' . $up['error'];
        }
    }
}

/* ---------- 附件：改标题 / 可见性 ---------- */
if (is_post() && post('act') === 'doc_meta') {
    if (!csrf_check()) {
        $err = t('enq.err_csrf');
    } else {
        $did = (int)post('doc_id', 0);
        doc_update_meta($did, [
            'title'   => trim((string)post('title')),
            'note'    => trim((string)post('note')),
            'visible' => (int)post('visible', 1) ? 1 : 0,
        ]);
        admin_log_action('update', 'document', $did, '');
        $msg = t('adm.saved');
    }
}

/* ---------- 附件：软删除 / 恢复 ---------- */
if (is_post() && post('act') === 'doc_del') {
    if (!csrf_check()) {
        $err = t('enq.err_csrf');
    } else {
        $did = (int)post('doc_id', 0);
        if (post('mode') === 'restore') {
            doc_restore($did);
            admin_log_action('restore', 'document', $did, '');
        } else {
            doc_soft_delete($did);
            admin_log_action('delete', 'document', $did, '');
        }
        $msg = t('adm.saved');
    }
}

/* ---------- 回复客户留言 ---------- */
if (is_post() && post('act') === 'reply') {
    if (!csrf_check()) {
        $err = t('enq.err_csrf');
    } else {
        $reply = trim((string)post('body'));
        if ($reply !== '' && $client) {
            db_exec('INSERT INTO messages (order_id,sender_role,sender_id,body) VALUES (?, "admin", ?, ?)',
                [$id, admin_id(), mb_substr($reply, 0, 4000)]);
            /* 勾选才发信：有时只是内部留个记录，不必再给客户发一封（2026-09-29） */
            if (ad_wants_notify()) {
                try {
                    mail_client_admin_reply($o, $client, mb_substr($reply, 0, 4000));
                } catch (Throwable $e) {
                    app_log('mail_client_admin_reply failed: ' . $e->getMessage(), 'ERROR');
                }
                $msg = t('adm.saved');
            } else {
                $msg = t('adm.notify_skipped');
            }
        }
    }
}

$o       = db_one('SELECT * FROM orders WHERE id = ?', [$id]);
// 软删的节点不显示（下面有「已删除节点」区可恢复）；003 没跑时退化为全部可见
$evtAlive = evt_alive_sql();
$events  = db_all('SELECT * FROM order_events WHERE order_id=?' . ($evtAlive ? ' AND ' . $evtAlive : '') . ' ORDER BY id DESC', [$id]);
$deletedEvents = [];
if (table_has_column('order_events', 'deleted_at')) {
    $deletedEvents = db_all('SELECT * FROM order_events WHERE order_id=? AND deleted_at IS NOT NULL ORDER BY id DESC', [$id]);
}
$pays    = db_all('SELECT * FROM payments WHERE order_id=? ORDER BY id DESC', [$id]);
/* 客户上传的待核对回单（联文档表取文件名/MIME 以便内嵌预览） */
ensure_receipts_table();
$receipts = db_all('SELECT r.*, d.original_name, d.mime, d.stored_name
                    FROM receipts r LEFT JOIN documents d ON d.id = r.doc_id
                    WHERE r.order_id = ? ORDER BY r.id DESC', [$id]);
/* 服务合同（2026-10-15）：签署状态 + 客户手写签名（签名 PNG 存 documents） */
ensure_contracts_table();
$contract = db_one('SELECT * FROM contracts WHERE order_id = ?', [$id]);
$contractSigId = ($contract && (int)$contract['signature_doc_id'] > 0) ? (int)$contract['signature_doc_id'] : 0;
// 后台要连「客户不可见」的附件一起看到；软删的不显示（可在下面恢复）
$docs    = doc_list('order', $id, ['include_hidden' => true]);
/* 回单与合同签名在下方的专属区块里单独展示，这里从通用文件列表剔除，
   避免同一份文件出现两处。 */
$receiptDocIds = [];
foreach ($receipts as $r) { if (!empty($r['doc_id'])) $receiptDocIds[(int)$r['doc_id']] = true; }
if ($contractSigId) $receiptDocIds[$contractSigId] = true;
if ($receiptDocIds) {
    $docs = array_values(array_filter($docs, function ($d) use ($receiptDocIds) {
        return !isset($receiptDocIds[(int)$d['id']]);
    }));
}
$msgs    = db_all('SELECT m.*, a.name AS admin_name FROM messages m
                   LEFT JOIN admin_users a ON a.id = m.sender_id AND m.sender_role = "admin"
                   WHERE m.order_id = ? ORDER BY m.id ASC', [$id]);
$classes = json_decode((string)$o['classes_json'], true);
if (!is_array($classes)) $classes = [];

/* 类别输入框的现值：两种存储结构都认（询价扁平 cls / 手工建单嵌套 class），
   去重后升序。**不能拿 classes_normalize() 取号** —— items 与 heading 都为空时
   它不返回该类别，手工建的单号会被漏掉。 */
$classesText = '';
$_cn = [];
if (is_array($classes)) {
    foreach ($classes as $c) {
        if (!is_array($c)) continue;
        $n = (int)preg_replace('/\D/', '', (string)($c['cls'] ?? $c['class'] ?? ''));
        if ($n > 0) $_cn[$n] = $n;
    }
}
if ($_cn) { ksort($_cn); $classesText = implode(', ', $_cn); }

$stages = ['pending_payment','paid','filed','examining','published','opposed','registered','refused','withdrawn','refunded','note'];

/* 附件按进度节点分桶：每个环节下面挂这个环节的文件（受理通知书、初审通知书…） */
$hasEventCol = table_has_column('documents', 'event_id');
$docsByEvent = [];
$looseDocs   = [];
foreach ($docs as $d) {
    $eid = isset($d['event_id']) ? (int)$d['event_id'] : 0;
    if ($eid > 0) {
        $docsByEvent[$eid][] = $d;
    } else {
        $looseDocs[] = $d;
    }
}
/* 软删的附件单独列出来，方便后悔 */
$deletedDocs = [];
if (table_has_column('documents', 'deleted_at')) {
    foreach (doc_list('order', $id, ['include_hidden' => true, 'include_deleted' => true]) as $d) {
        if (!empty($d['deleted_at'])) $deletedDocs[] = $d;
    }
}

/** 一个附件的一行（后台：可改标题/可见性、可下载、可软删） */
$docRow = function ($d) use ($id) {
    $title = empty($d['title']) ? (string)$d['original_name'] : (string)$d['title'];
    $icon  = 'fa-file-lines';
    if (strpos((string)$d['mime'], 'image/') === 0)      $icon = 'fa-file-image';
    elseif (strpos((string)$d['mime'], 'pdf') !== false) $icon = 'fa-file-pdf';
    elseif (strpos((string)$d['mime'], 'word') !== false
         || strpos((string)$d['mime'], 'officedocument') !== false) $icon = 'fa-file-word';
    $hidden = empty($d['visible']);
    ob_start(); ?>
    <div style="border:1px solid var(--bd);border-radius:8px;padding:10px 12px;margin-bottom:8px;background:<?php echo $hidden ? '#fff7ed' : '#fff'; ?>;">
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:13px;">
            <i class="fas <?php echo $icon; ?>" style="color:var(--pri);"></i>
            <b><?php echo h($title); ?></b>
            <?php if ($title !== (string)$d['original_name']): ?>
                <span class="lg-sm" style="opacity:.55;"><?php echo h($d['original_name']); ?></span>
            <?php endif; ?>
            <span class="ad-tag"><?php echo h($d['kind']); ?></span>
            <?php if ($d['sensitive']): ?><span class="ad-tag new" style="background:#fee2e2;color:#991b1b;"><?php echo h(t('adm.sensitive_warn')); ?></span><?php endif; ?>
            <?php if ($hidden): ?><span class="ad-tag closed"><?php echo h(t('adm.internal')); ?></span><?php endif; ?>
            <span class="lg-sm" style="opacity:.55;"><?php echo h(round($d['size'] / 1024, 1)); ?> KB · <?php echo h($d['created_at']); ?></span>
            <span style="margin-left:auto;">
                <a class="btn btn-s" href="<?php echo h(site_url('app/admin/file.php?id=' . (int)$d['id'])); ?>"><i class="fas fa-download"></i> <?php echo h(t('download')); ?></a>
            </span>
        </div>
        <?php if (!empty($d['note'])): ?>
            <div style="font-size:12.5px;color:#4b5563;margin-top:4px;"><?php echo h($d['note']); ?></div>
        <?php endif; ?>
        <details style="margin-top:6px;">
            <summary style="cursor:pointer;font-size:12.5px;color:var(--pri);"><?php echo h(t('edit')); ?> / <?php echo h(t('delete')); ?></summary>
            <form method="post" class="form-row" style="align-items:flex-end;margin-top:10px;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="act" value="doc_meta">
                <input type="hidden" name="doc_id" value="<?php echo (int)$d['id']; ?>">
                <div><label><?php echo tp('adm.doc_title'); ?></label>
                    <input type="text" name="title" value="<?php echo h($d['title'] ?? ''); ?>"></div>
                <div><label><?php echo tp('note'); ?></label>
                    <input type="text" name="note" value="<?php echo h($d['note'] ?? ''); ?>"></div>
                <div><label><?php echo tp('adm.doc_visible'); ?></label>
                    <select name="visible">
                        <option value="1" <?php echo empty($d['visible']) ? '' : 'selected'; ?>><?php echo h(t('yes')); ?></option>
                        <option value="0" <?php echo empty($d['visible']) ? 'selected' : ''; ?>><?php echo h(t('no')); ?></option>
                    </select></div>
                <div style="display:flex;align-items:flex-end;"><button class="btn" type="submit"><?php echo h(t('save')); ?></button></div>
            </form>
            <form method="post" style="margin-top:8px;" onsubmit="return confirm('<?php echo h(t('adm.confirm_delete')); ?>');">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="act" value="doc_del">
                <input type="hidden" name="doc_id" value="<?php echo (int)$d['id']; ?>">
                <input type="hidden" name="mode" value="delete">
                <button class="btn btn-s btn-d" type="submit"><i class="fas fa-trash"></i> <?php echo h(t('delete')); ?></button>
                <span class="lg-sm" style="margin-left:8px;opacity:.7;font-size:12px;"><?php echo lang() === 'zh' ? '软删除：文件与记录都保留，可恢复。' : 'Soft delete — the file and its record are kept and can be restored.'; ?></span>
            </form>
        </details>
    </div>
    <?php return ob_get_clean();
};

admin_header('adm.orders', 'orders');
?>
<?php if ($msg): ?><?php echo ad_flash($msg, 'ok'); ?><?php endif; ?>
<?php if ($err): ?><?php echo ad_flash($err, 'bad'); ?><?php endif; ?>

<div class="ad-card" data-workspace-tab="overview" data-workspace-label="Overview">
    <h2>
        <span class="ad-mono"><?php echo h($o['ref']); ?></span> · <?php echo h($o['mark_name']); ?>
        <span class="ad-tag <?php echo in_array($o['status'], ['paid','registered'], true) ? 'done' : ''; ?>" style="margin-left:8px;"><?php echo h(order_status_label($o['status'])); ?></span>
    </h2>
    <div class="form-row">
        <div><label><?php echo tp('acc.mark'); ?></label><div><?php echo h($o['mark_name']); ?> <span class="lg-sm" style="opacity:.7;"><?php echo h($o['mark_type']); ?></span></div></div>
        <div><label>Device</label><div><?php if (!empty($o['device_file_id'])): ?><a href="<?php echo h(site_url('app/admin/file.php?id=' . (int)$o['device_file_id'])); ?>" target="_blank"><i class="fas fa-image"></i> <?php echo h(t('view')); ?> / <?php echo h(t('download')); ?></a><?php else: ?>—<?php endif; ?></div></div>
        <div><label><?php echo tp('acc.registrant'); ?></label><div><?php echo h($o['registrant_name']); ?><div class="lg-sm" style="opacity:.7;"><?php echo h($o['registrant_addr']); ?></div></div></div>
        <div><label><?php echo tp('client'); ?></label><div><?php echo h($client['name'] ?? '—'); ?> &lt;<?php echo h($client['email'] ?? ''); ?>&gt;</div></div>
        <div><label><?php echo tp('adm.total_due'); ?></label><div><b><?php echo h(money($o['total'], $o['currency'])); ?></b></div></div>
        <div><label><?php echo tp('adm.paid_amount'); ?></label><div><?php echo h(money($o['paid_amount'], $o['currency'])); ?></div></div>
        <div><label><?php echo tp('acc.app_no'); ?></label><div class="ad-mono"><?php echo h($o['cnipa_app_no'] ?: '—'); ?></div></div>
        <?php if (table_has_column('orders', 'cnipa_reg_no')): ?>
        <div><label>Reg. No.</label><div class="ad-mono"><?php echo h(isset($o['cnipa_reg_no']) && $o['cnipa_reg_no'] !== '' ? $o['cnipa_reg_no'] : '—'); ?></div></div>
        <?php endif; ?>
    </div>
    <?php
    /* 商品和服务：优先手工输入的 goods_services；没填则回退显示询价带来的类别/商品。
     * NULL 与空串要分开：NULL = 从未填写（跟随询价类别），'' = 管理员显式清空（隐藏该行），
     * 否则「leave empty to hide」这句永远做不到 —— 清空只会退回显示询价数据（2026-10-17）。 */
    $gsRaw    = table_has_column('orders', 'goods_services') ? ($o['goods_services'] ?? null) : null;
    $gs       = $gsRaw === null ? '' : trim((string)$gsRaw);
    $gsHidden = ($gsRaw !== null && $gs === '');

    /* 询价带来的类别/商品拆成「类号 + 逐条商品」，供两处共用（见 2026-10-17 修复）：
     *   ① 这里只读展示 —— 与询价页 enquiry.php 同构：类号一行、商品一条一行；
     *      原先 implode('; ') 把 10 个商品挤成一句，看不出来也数不清。
     *   ② 下方编辑框的默认值（$gsDerived）—— 与询价数据互通，不必照着重敲。
     * 文本渲染统一走 order_classes_text()，展示与编辑框不会各说各话。 */
    $gsRows = [];
    foreach (classes_normalize($classes) as $cls => $its) {
        $groups = [];
        foreach ($its as $it) {
            $grp = $it['grp'];
            $key = $grp !== '' ? $grp : '__ungrouped';
            if (!isset($groups[$key])) $groups[$key] = ['grp' => $grp, 'grp_name' => $it['grp_name'], 'lines' => []];
            $line = $it['name'] !== '' && $it['en'] !== '' ? $it['name'] . ' / ' . $it['en'] : ($it['name'] !== '' ? $it['name'] : $it['en']);
            if ($line !== '') $groups[$key]['lines'][] = ($it['code'] !== '' ? $it['code'] . ' · ' : '') . $line;
        }
        $gsRows[] = ['cls' => (int)$cls, 'groups' => array_values($groups)];
    }
    $gsDerived = order_classes_text($classes);
    ?>
    <div style="margin-top:10px;">
        <label style="font-weight:700;font-size:12.5px;">Goods &amp; services</label>
        <?php if ($gsHidden): ?>
            <div class="lg-sm" style="opacity:.6;">— (hidden for this order)</div>
        <?php elseif ($gs !== ''): ?>
            <div style="font-size:13px;line-height:1.8;white-space:pre-wrap;"><?php echo h($gs); ?></div>
        <?php elseif ($gsRows): ?>
            <div style="font-size:12.5px;color:#4b5563;">
                <span class="lg-sm" style="opacity:.65;">(from the enquiry — not manually entered; CN / EN as submitted)</span>
                <?php foreach ($gsRows as $r): ?>
                    <div style="margin-top:7px;font-weight:700;font-size:13px;color:#111827;">Class <?php echo (int)$r['cls']; ?></div>
                    <?php foreach ($r['groups'] as $group): ?>
                        <?php if ($group['grp'] !== ''): ?><div style="margin:4px 0 2px;font-weight:700;color:#4a5fc1;">Similar-goods group <?php echo h($group['grp']); ?><?php echo $group['grp_name'] !== '' ? ' · ' . h($group['grp_name']) : ''; ?></div><?php endif; ?>
                        <div style="line-height:1.85;">
                            <?php foreach ($group['lines'] as $ln): ?>
                                • <?php echo h($ln); ?><br>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="lg-sm" style="opacity:.6;">— (enter manually in the form below)</div>
        <?php endif; ?>
    </div>
    <div style="margin-top:10px;">
        <a class="btn btn-s" href="<?php echo h(site_url('app/admin/enquiry.php?id=' . ($o['enquiry_id'] ?: 0))); ?>"><?php echo h(t('adm.enquiries')); ?></a>
        <a class="btn btn-s" href="<?php echo h(site_url('app/admin/clients.php?q=' . urlencode($client['email'] ?? ''))); ?>"><?php echo h(t('client')); ?></a>
    </div>
</div>

<?php /* 直达本单的链接：线下邮件/WhatsApp 里手动发给客户时用。
         传了订单 id，客户点开即登录并直接落在这张订单上（2026-09-30） */ ?>
<?php echo ad_login_link_card($client, (int)$o['id']); ?>
<script>
<?php echo ad_copy_link_js(); ?>
</script>

<!-- 状态与订单内容 -->
<div class="ad-card" data-workspace-tab="details" data-workspace-label="Order details">
    <h2><?php echo tp('status'); ?> / <?php echo tp('detail'); ?></h2>
    <div class="lg-sm" style="opacity:.7;margin:-4px 0 12px;">
        <?php echo lang() === 'zh'
            ? '商标名称、类别与总价建单后都可直接改 —— 客户改需求（如 9 件减为 5 件）或重新报价时，不必删单重建。'
            : 'Mark name, classes and total price stay editable after the order is created — a changed request (e.g. 9 marks down to 5) or a re-quote no longer needs a delete-and-recreate.'; ?>
    </div>
    <form method="post">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="act" value="update">
        <div class="form-row">
            <div><label for="status"><?php echo tp('status'); ?></label>
                <select name="status" id="status">
                    <?php foreach (['pending_payment','paid','filed','examining','published','opposed','registered','refused','withdrawn','refunded'] as $s): ?>
                        <option value="<?php echo h($s); ?>" <?php echo $o['status'] === $s ? 'selected' : ''; ?>><?php echo h(order_status_label($s)); ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div><label for="mark_name"><?php echo tp('acc.mark'); ?></label>
                <input type="text" id="mark_name" name="mark_name" value="<?php echo h($o['mark_name']); ?>">
                <div class="lg-sm" style="opacity:.6;margin-top:3px;"><?php echo h(t('adm.mark_name_hint')); ?></div></div>
            <div><label for="mark_type"><?php echo tp('acc.mark_type'); ?></label>
                <select name="mark_type" id="mark_type">
                    <?php foreach (['word','device','combined','sound','other'] as $mt): ?>
                        <option value="<?php echo h($mt); ?>" <?php echo $o['mark_type'] === $mt ? 'selected' : ''; ?>><?php echo h($mt); ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div><label for="classes"><?php echo tp('acc.classes'); ?></label>
                <input type="text" id="classes" name="classes" value="<?php echo h($classesText); ?>"
                       placeholder="25, 34">
                <div class="lg-sm" style="opacity:.6;margin-top:3px;"><?php echo h(t('adm.classes_hint')); ?></div></div>
            <div><label for="existing_reg_no"><?php echo tp('acc.existing_reg_no'); ?></label>
                <input type="text" id="existing_reg_no" name="existing_reg_no" value="<?php echo h($o['existing_reg_no']); ?>"></div>
            <div><label for="cnipa_app_no">CNIPA application no.</label>
                <input type="text" id="cnipa_app_no" name="cnipa_app_no" value="<?php echo h($o['cnipa_app_no']); ?>"></div>
            <?php if (table_has_column('orders', 'cnipa_reg_no')): ?>
            <div><label for="cnipa_reg_no">CNIPA registration no.</label>
                <input type="text" id="cnipa_reg_no" name="cnipa_reg_no" value="<?php echo h($o['cnipa_reg_no'] ?? ''); ?>"></div>
            <?php endif; ?>
            <div><label for="registrant_name">Registrant</label>
                <input type="text" id="registrant_name" name="registrant_name" value="<?php echo h($o['registrant_name']); ?>"></div>
            <div><label for="registrant_addr">Registrant address</label>
                <input type="text" id="registrant_addr" name="registrant_addr" value="<?php echo h($o['registrant_addr']); ?>"></div>
            <div><label for="registrant_type"><?php echo tp('acc.registrant_type'); ?></label>
                <select name="registrant_type" id="registrant_type">
                    <?php foreach (['company','individual','other'] as $rt): ?>
                        <option value="<?php echo h($rt); ?>" <?php echo $o['registrant_type'] === $rt ? 'selected' : ''; ?>><?php echo h($rt); ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div><label for="total"><?php echo h(t('adm.total_price')); ?> (<?php echo h($o['currency']); ?>)</label>
                <input type="number" id="total" name="total" step="0.01" min="0" value="<?php echo h(number_format((float)$o['total'], 2, '.', '')); ?>">
                <div class="lg-sm" style="opacity:.6;margin-top:3px;">
                    <?php echo h(t('adm.total_hint')); ?>
                    <?php if ((float)$o['paid_amount'] > 0): ?>
                        <br><?php echo h(t('adm.paid_amount')); ?>: <?php echo h(money($o['paid_amount'], $o['currency'])); ?>
                    <?php endif; ?>
                </div></div>
        </div>
        <?php if (table_has_column('orders', 'goods_services')): ?>
        <?php
        /* 默认值：手工存过就用存的那份；隐藏状态就留空（不要把询价数据又填回来）；
           都没动过则用询价带来的商品预填，打开就能看/改，不用照询价页重敲一遍（2026-10-17）。 */
        $gsValue = $gs !== '' ? $gs : ($gsHidden ? '' : $gsDerived);
        ?>
        <div style="margin-top:4px;">
            <label for="goods_services">Goods &amp; services (one item per line; leave empty to hide)</label>
            <textarea id="goods_services" name="goods_services" rows="6" style="width:100%;"
                      placeholder="Class 25: clothing, footwear, headgear"><?php echo h($gsValue); ?></textarea>
            <?php if ($gs === '' && !$gsHidden && $gsDerived !== ''): ?>
                <div class="lg-sm" style="opacity:.65;margin-top:4px;line-height:1.6;">
                    Pre-filled from the enquiry classes — edit and save to keep your own wording; submit it empty to hide this row.
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <button class="btn btn-p" type="submit"><?php echo h(t('save')); ?></button>
    </form>

    <?php if (!empty($o['voided_at'])): ?>
        <div class="ad-alert bad" style="margin-top:14px;">
            <?php echo h(t('adm.voided')); ?> · <?php echo h($o['voided_at']); ?>
            <?php if (!empty($o['void_reason'])): ?> · <?php echo h($o['void_reason']); ?><?php endif; ?>
        </div>
        <form method="post" style="margin-top:10px;" onsubmit="return confirm('<?php echo lang() === 'zh' ? '恢复这笔作废订单？' : 'Restore this voided order?'; ?>');">
            <?php echo csrf_field(); ?><input type="hidden" name="act" value="restore_void">
            <button class="btn btn-p" type="submit"><i class="fas fa-undo"></i> <?php echo lang() === 'zh' ? '恢复订单' : 'Restore order'; ?></button>
            <span class="lg-sm" style="margin-left:8px;opacity:.7;"><?php echo lang() === 'zh' ? '恢复到最近一次记录的业务状态。' : 'Restores the last recorded business stage.'; ?></span>
        </form>
    <?php endif; ?>
</div>

<!-- 作废 -->
<?php if (empty($o['voided_at'])): ?>
<div class="ad-card" data-workspace-tab="actions" data-workspace-label="Order actions">
    <h2 style="color:var(--bad);"><i class="fas fa-ban"></i> <?php echo h(t('adm.void_order')); ?></h2>
    <form method="post" onsubmit="return confirm('<?php echo h(t('adm.confirm_delete')); ?>');">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="act" value="void">
        <div class="form-row">
            <div><label for="void_reason"><?php echo tp('adm.void_reason'); ?></label>
                <input type="text" id="void_reason" name="void_reason"
                       placeholder="<?php echo lang() === 'zh' ? '如：客户取消 / 重复建单 / 录入错误' : 'e.g. client cancelled / duplicate / data entry error'; ?>"></div>
        </div>
        <button class="btn btn-d" type="submit"><i class="fas fa-ban"></i> <?php echo h(t('adm.void_order')); ?></button>
        <span class="lg-sm" style="margin-left:10px;opacity:.7;">
            <?php echo lang() === 'zh'
                ? '不做物理删除：订单会标记为「已作废」并保留全部记录，客户登录后仍可看到历史。'
                : 'Nothing is deleted: the order is marked void and keeps all its records, and the client can still see the history.'; ?>
        </span>
    </form>
</div>
<?php endif; ?>

<!-- 彻底删除（物理删除，不可逆） -->
<?php if (admin_can_hard_delete()): ?>
<div class="ad-card" data-workspace-tab="actions" data-workspace-label="Order actions" style="border-color:#fecaca;">
    <h2 style="color:#b91c1c;"><i class="fas fa-radiation"></i> <?php echo lang() === 'zh' ? '彻底删除订单' : 'Permanently delete this order'; ?></h2>
    <div class="lg-sm" style="opacity:.8;line-height:1.8;margin-bottom:10px;">
        <?php echo lang() === 'zh'
            ? '从数据库中<b>物理删除</b>：进度节点、付款记录、留言、附件（含磁盘文件）一并清掉，<b>不可恢复</b>。只是不想让客户付款的话，用上面的「作废」即可。'
            : 'Physically removes the order from the database — progress events, payments, messages and uploaded files (including the files on disk) are all deleted. <b>This cannot be undone.</b> Use "void" above if you only want to stop payment.'; ?>
    </div>
    <form method="post" onsubmit="return confirmHardDelete(this, <?php echo json_encode($o['ref']); ?>);">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="act" value="hard_delete">
        <div class="form-row">
            <div><label for="confirm_text"><?php echo lang() === 'zh' ? '请输入订单号以确认' : 'Type the order ref to confirm'; ?> <span class="ad-mono" style="color:#b91c1c;"><?php echo h($o['ref']); ?></span></label>
                <input type="text" id="confirm_text" name="confirm_text" autocomplete="off" placeholder="<?php echo h($o['ref']); ?>"></div>
        </div>
        <button class="btn btn-d" type="submit"><i class="fas fa-trash-can"></i> <?php echo lang() === 'zh' ? '我确认，永久删除' : 'Yes, delete permanently'; ?></button>
    </form>
</div>
<script>
function confirmHardDelete(form, ref) {
    var zh = <?php echo json_encode(lang() === 'zh'); ?>;
    var v = (form.querySelector('[name="confirm_text"]').value || '').trim();
    if (v !== ref) {
        alert((zh ? '请输入订单号 ' : 'Please type the order ref ') + ref + (zh ? ' 才能删除。' : ' to confirm deletion.'));
        return false;
    }
    return confirm(zh
        ? '确认永久删除订单 ' + ref + '？此操作不可恢复。'
        : 'Permanently delete order ' + ref + '? This cannot be undone.');
}
</script>
<?php else: ?>
<div class="ad-card" data-workspace-tab="actions" data-workspace-label="Order actions">
    <div class="lg-sm" style="opacity:.7;">
        <?php echo lang() === 'zh' ? '当前账号（只读）无权执行物理删除。' : 'Your account (viewer) is not allowed to delete records.'; ?>
    </div>
</div>
<?php endif; ?>

<!-- 进度时间线 -->
<div class="ad-card" data-workspace-tab="timeline" data-workspace-label="Progress">
    <h2><?php echo tp('acc.timeline'); ?></h2>
    <form method="post">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="act" value="event">
        <div class="form-row">
            <div><label for="stage">Stage</label>
                <select name="stage" id="stage">
                    <?php foreach ($stages as $s): ?><option value="<?php echo h($s); ?>"><?php echo h(order_status_label($s)); ?></option><?php endforeach; ?>
                </select></div>
            <div><label for="title_en">Title (EN)</label><input type="text" id="title_en" name="title_en"></div>
            <div><label for="title_zh">Title (ZH, optional)</label><input type="text" id="title_zh" name="title_zh"></div>
            <div><label for="visible"><?php echo tp('visible_to_client'); ?></label>
                <select name="visible" id="visible"><option value="1"><?php echo h(t('yes')); ?></option><option value="0"><?php echo h(t('no')); ?></option></select></div>
        </div>
        <div class="form-row">
            <div><label for="note"><?php echo tp('note'); ?></label><textarea id="note" name="note" rows="2"></textarea></div>
        </div>
        <?php echo ad_notify_field(true, 'notify_event'); ?>
        <button class="btn btn-p" type="submit"><i class="fas fa-plus"></i> <?php echo h(t('adm.add_event')); ?></button>
    </form>

    <div style="margin-top:16px;">
        <?php if (!$events): ?><div class="ad-empty"><?php echo h(t('adm.no_data')); ?></div><?php endif; ?>
        <?php foreach ($events as $ev): ?>
            <div style="border-left:3px solid <?php echo $ev['visible'] ? 'var(--pri)' : '#cbd5e1'; ?>;padding:8px 12px;margin-bottom:8px;font-size:13px;">
                <div style="display:flex;gap:8px;align-items:center;">
                    <span class="ad-tag"><?php echo h(order_status_label($ev['stage'])); ?></span>
                    <b><?php echo h($ev['title_en'] ?: $ev['title_zh']); ?></b>
                    <?php if ($ev['title_zh']): ?><span class="lg-sm" style="opacity:.6;"><?php echo h($ev['title_zh']); ?></span><?php endif; ?>
                    <span class="lg-sm" style="margin-left:auto;opacity:.6;"><?php echo h($ev['created_at']); ?></span>
                    <?php if (!empty($docsByEvent[(int)$ev['id']])): ?>
                        <span class="ad-tag"><i class="fas fa-paperclip"></i> <?php echo count($docsByEvent[(int)$ev['id']]); ?></span>
                    <?php endif; ?>
                    <?php if (!$ev['visible']): ?><span class="ad-tag closed"><?php echo h(t('adm.internal')); ?></span><?php endif; ?>
                </div>
                <?php if ($ev['note']): ?><div style="white-space:pre-wrap;color:#4b5563;margin-top:4px;"><?php echo h($ev['note']); ?></div><?php endif; ?>

                <!-- 编辑 / 删除 -->
                <details style="margin-top:6px;">
                    <summary style="cursor:pointer;font-size:12px;color:var(--pri);user-select:none;">
                        <?php echo h(t('edit')); ?> / <?php echo h(t('delete')); ?>
                    </summary>
                    <form method="post" class="form-row" style="align-items:flex-end;margin-top:10px;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="act" value="event_edit">
                        <input type="hidden" name="event_id" value="<?php echo (int)$ev['id']; ?>">
                        <div><label>Stage</label>
                            <select name="stage">
                                <?php foreach ($stages as $s): ?>
                                    <option value="<?php echo h($s); ?>" <?php echo $ev['stage'] === $s ? 'selected' : ''; ?>><?php echo h(order_status_label($s)); ?></option>
                                <?php endforeach; ?>
                            </select></div>
                        <div><label>Title (EN)</label>
                            <input type="text" name="title_en" value="<?php echo h($ev['title_en']); ?>"></div>
                        <div><label>Title (ZH, optional)</label>
                            <input type="text" name="title_zh" value="<?php echo h($ev['title_zh']); ?>"></div>
                        <div><label><?php echo tp('visible_to_client'); ?></label>
                            <select name="visible">
                                <option value="1" <?php echo $ev['visible'] ? 'selected' : ''; ?>><?php echo h(t('yes')); ?></option>
                                <option value="0" <?php echo $ev['visible'] ? '' : 'selected'; ?>><?php echo h(t('no')); ?></option>
                            </select></div>
                        <div style="grid-column:1 / -1;"><label><?php echo tp('note'); ?></label>
                            <textarea name="note" rows="2"><?php echo h($ev['note']); ?></textarea></div>
                        <div style="display:flex;align-items:flex-end;gap:8px;">
                            <button class="btn btn-p btn-s" type="submit"><i class="fas fa-floppy-disk"></i> <?php echo h(t('save')); ?></button>
                        </div>
                        <div class="lg-sm" style="font-size:12px;opacity:.7;align-self:center;">
                            <?php echo lang() === 'zh'
                                ? '改成「客户可见」不会自动补发邮件；需要客户知道时请另加一条节点。'
                                : 'Switching to visible does not re-send the email — add a new event if the client should be notified.'; ?>
                        </div>
                    </form>
                    <form method="post" style="margin-top:8px;" onsubmit="return confirm('<?php echo h(t('adm.confirm_delete')); ?>');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="act" value="event_del">
                        <input type="hidden" name="event_id" value="<?php echo (int)$ev['id']; ?>">
                        <button class="btn btn-s btn-d" type="submit"><i class="fas fa-trash"></i> <?php echo h(t('delete')); ?></button>
                        <span class="lg-sm" style="margin-left:8px;opacity:.7;font-size:12px;">
                            <?php echo lang() === 'zh'
                                ? '软删除：节点与挂在上面的附件都保留，可恢复。'
                                : 'Soft delete — the event and its attachments are kept and can be restored.'; ?>
                        </span>
                    </form>
                </details>
            </div>
        <?php endforeach; ?>

        <?php if ($deletedEvents): ?>
        <div style="margin-top:14px;border-top:1px dashed var(--bd);padding-top:10px;">
            <div style="font-size:12.5px;font-weight:700;color:var(--gray);margin-bottom:6px;">
                <?php echo lang() === 'zh' ? '已删除的节点（可恢复）' : 'Deleted events (can be restored)'; ?>
            </div>
            <?php foreach ($deletedEvents as $ev): ?>
                <div style="display:flex;gap:8px;align-items:center;font-size:12.5px;padding:6px 0;border-bottom:1px solid #f1f3f7;color:var(--gray);">
                    <span class="ad-tag closed"><?php echo h(order_status_label($ev['stage'])); ?></span>
                    <span style="flex:1;"><?php echo h($ev['title_en'] ?: $ev['title_zh']); ?></span>
                    <span><?php echo h($ev['created_at']); ?></span>
                    <span><?php echo h($ev['deleted_at']); ?></span>
                    <form method="post" style="display:inline;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="act" value="event_del">
                        <input type="hidden" name="event_id" value="<?php echo (int)$ev['id']; ?>">
                        <input type="hidden" name="mode" value="restore">
                        <button class="btn btn-s" type="submit"><i class="fas fa-rotate-left"></i> <?php echo lang() === 'zh' ? '恢复' : 'Restore'; ?></button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- 付款 -->
<div class="ad-card" data-workspace-tab="payments" data-workspace-label="Payments">
    <h2><?php echo tp('pay.title'); ?></h2>
    <table class="ad-tb">
        <tr><th><?php echo tp('adm.payment_method'); ?></th><th><?php echo tp('amount'); ?></th>
            <th><?php echo tp('status'); ?></th><th><?php echo tp('adm.payment_ref'); ?></th><th><?php echo tp('date'); ?></th></tr>
        <?php if (!$pays): ?><tr><td colspan="5"><div class="ad-empty"><?php echo h(t('adm.no_data')); ?></div></td></tr><?php endif; ?>
        <?php foreach ($pays as $p): ?>
        <tr>
            <td><?php echo h($p['method']); ?></td>
            <td><?php echo h(money($p['amount'], $p['currency'])); ?></td>
            <td><span class="ad-tag <?php echo $p['status'] === 'completed' ? 'done' : ''; ?>"><?php echo h($p['status']); ?></span></td>
            <td class="ad-mono"><?php echo h($p['provider_ref']); ?></td>
            <td><?php echo h($p['paid_at'] ?: $p['created_at']); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <form method="post" style="margin-top:14px;">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="act" value="mark_paid">
        <?php echo idem_field('mark_paid_' . $id); ?>
        <div class="form-row">
            <div><label for="amount"><?php echo tp('adm.mark_paid'); ?></label>
                <input type="number" step="0.01" id="amount" name="amount" value="<?php echo h(max(0, (float)$o['total'] - (float)$o['paid_amount'])); ?>"></div>
            <div style="display:flex;align-items:flex-end;"><button class="btn" type="submit"><?php echo h(t('confirm')); ?></button></div>
        </div>
        <?php echo ad_notify_field(true, 'notify_mark_paid'); ?>
    </form>

    <?php if (!empty($receipts)): ?>
    <div style="margin-top:20px;padding-top:16px;border-top:1px dashed #d1d5db;">
        <h3 style="font-size:14px;margin:0 0 10px;"><?php echo tp('adm.receipts'); ?></h3>
        <?php foreach ($receipts as $r):
            $stMap = ['pending' => '#92400e', 'confirmed' => '#047857', 'rejected' => '#b91c1c'];
            $stColor = $stMap[$r['status']] ?? '#6b7280';
            $stText = $r['status'] === 'confirmed' ? t('pay.receipt_confirmed')
                    : ($r['status'] === 'rejected' ? t('pay.receipt_rejected') : t('pay.receipt_pending'));
            $rid = (int)$r['id'];
        ?>
        <div style="border:1px solid #e5e7eb;border-radius:9px;padding:12px 14px;margin-bottom:12px;background:#fafbff;">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:8px;">
                <div style="font-weight:700;font-size:13.5px;">
                    <?php echo h(($r['currency'] ?: 'USD') . ' ' . number_format((float)$r['amount_declared'], 2)); ?>
                    <span style="font-weight:400;color:#9ca3af;font-size:12px;margin-left:6px;"><?php echo h($r['created_at']); ?></span>
                </div>
                <span style="font-size:11.5px;font-weight:700;color:<?php echo $stColor; ?>;"><?php echo h($stText); ?></span>
            </div>
            <?php if (!empty($r['doc_id'])): ?>
                <?php if (strpos((string)$r['mime'], 'image/') === 0): ?>
                    <a href="file.php?id=<?php echo (int)$r['doc_id']; ?>&inline=1" target="_blank">
                        <img src="file.php?id=<?php echo (int)$r['doc_id']; ?>&inline=1" style="max-width:220px;max-height:180px;border:1px solid #e5e7eb;border-radius:6px;display:block;margin-bottom:8px;">
                    </a>
                <?php endif; ?>
                <div style="margin-bottom:8px;">
                    <a href="file.php?id=<?php echo (int)$r['doc_id']; ?>" target="_blank"><?php echo h($r['original_name'] ?: t('adm.receipt_view')); ?></a>
                </div>
            <?php endif; ?>
            <?php if ($r['status'] === 'pending'): ?>
            <form method="post" style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;margin-top:6px;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="act" value="confirm_receipt">
                <input type="hidden" name="receipt_id" value="<?php echo $rid; ?>">
                <?php echo idem_field('confirm_receipt_' . $rid); ?>
                <div><label for="ramt<?php echo $rid; ?>" style="font-size:12px;"><?php echo tp('amount'); ?></label>
                    <input type="number" step="0.01" id="ramt<?php echo $rid; ?>" name="amount" value="<?php echo h((float)$r['amount_declared'] > 0 ? (float)$r['amount_declared'] : max(0, (float)$o['total'] - (float)$o['paid_amount'])); ?>" style="width:140px;"></div>
                <div><button class="btn" type="submit"><?php echo tp('adm.confirm_receipt'); ?></button></div>
                <?php echo ad_notify_field(true, 'notify_confirm_receipt_' . $rid); ?>
            </form>
            <form method="post" style="margin-top:8px;display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="act" value="reject_receipt">
                <input type="hidden" name="receipt_id" value="<?php echo $rid; ?>">
                <?php echo idem_field('reject_receipt_' . $rid); ?>
                <div style="flex:1;min-width:200px;"><label style="font-size:12px;"><?php echo tp('adm.reject_reason'); ?></label>
                    <input type="text" name="reject_note" style="width:100%;"></div>
                <div><button class="btn" type="submit" style="background:#b91c1c;"><?php echo tp('adm.reject_receipt'); ?></button></div>
            </form>
            <?php elseif (!empty($r['note'])): ?>
                <div style="font-size:12px;color:#6b7280;"><?php echo h($r['note']); ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- 服务合同（客户在线手写签署，2026-10-15） -->
<?php if (!empty($contract)): ?>
<div class="ad-card" data-workspace-tab="contract" data-workspace-label="Contract">
    <h2><?php echo lang() === 'zh' ? '服务合同' : 'Service agreement'; ?>
        <?php if ($contract['status'] === 'signed'): ?>
            <span class="ad-tag done" style="margin-left:8px;"><?php echo lang() === 'zh' ? '已签署' : 'Signed'; ?></span>
        <?php else: ?>
            <span class="ad-tag" style="margin-left:8px;"><?php echo lang() === 'zh' ? '草稿' : 'Draft'; ?></span>
        <?php endif; ?>
    </h2>
    <table class="ad-tb">
        <tr><th style="width:180px;"><?php echo lang() === 'zh' ? '合同编号' : 'Agreement ref.'; ?></th>
            <td class="ad-mono"><?php echo h($o['ref']); ?></td></tr>
        <?php if ($contract['status'] === 'signed'): ?>
        <tr><th><?php echo lang() === 'zh' ? '签署人' : 'Signed by'; ?></th><td><?php echo h($contract['signed_name']); ?></td></tr>
        <tr><th><?php echo lang() === 'zh' ? '签署时间' : 'Signed at'; ?></th><td><?php echo h($contract['signed_at']); ?></td></tr>
        <?php endif; ?>
    </table>
    <?php if ($contractSigId): ?>
    <div style="margin-top:12px;">
        <a href="file.php?id=<?php echo $contractSigId; ?>&inline=1" target="_blank">
            <img src="file.php?id=<?php echo $contractSigId; ?>&inline=1" alt="signature" style="max-width:260px;max-height:140px;border:1px solid #e5e7eb;border-radius:6px;background:#fff;display:block;">
        </a>
        <div style="font-size:12px;color:#6b7280;margin-top:6px;"><?php echo lang() === 'zh' ? '客户手写签名' : 'Client handwritten signature'; ?></div>
    </div>
    <?php endif; ?>
    <div style="font-size:12px;color:#9ca3af;margin-top:10px;"><?php echo lang() === 'zh'
        ? '合同由客户在客户端在线签署；完整文本以客户端页面为准。'
        : 'Signed online by the client in the client center; the full text lives on the client-facing page.'; ?></div>
</div>
<?php endif; ?>

<!-- 文件（按进度节点分组） -->
<div class="ad-card" data-workspace-tab="documents" data-workspace-label="Documents">
    <h2><?php echo tp('acc.documents'); ?>
        <span class="lg-sm" style="font-weight:400;opacity:.7;margin-left:6px;">
            <?php echo lang() === 'zh' ? '支持图片 / PDF / Word，单个最大 ' : 'Images, PDF and Word — up to '; ?><?php echo (int)cfg('storage.max_upload_mb', 16); ?>MB
        </span>
    </h2>

    <?php if (!$docs): ?><div class="ad-empty"><?php echo h(t('adm.no_data')); ?></div><?php endif; ?>

    <?php if ($hasEventCol): ?>
        <?php foreach ($events as $ev): ?>
            <?php if (empty($docsByEvent[(int)$ev['id']])) continue; ?>
            <div style="margin-bottom:14px;">
                <div style="font-size:12.5px;font-weight:700;color:#334155;margin-bottom:6px;">
                    <span class="ad-tag"><?php echo h(order_status_label($ev['stage'])); ?></span>
                    <?php echo h($ev['title_zh'] ?: $ev['title_en']); ?>
                    <span class="lg-sm" style="opacity:.5;"><?php echo h($ev['created_at']); ?></span>
                </div>
                <?php foreach ($docsByEvent[(int)$ev['id']] as $d) echo $docRow($d); ?>
            </div>
        <?php endforeach; ?>
        <?php if ($looseDocs): ?>
            <div style="margin-bottom:14px;">
                <div style="font-size:12.5px;font-weight:700;color:#334155;margin-bottom:6px;"><?php echo h(t('adm.no_step')); ?></div>
                <?php foreach ($looseDocs as $d) echo $docRow($d); ?>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <?php foreach ($docs as $d) echo $docRow($d); ?>
    <?php endif; ?>

    <?php if ($deletedDocs): ?>
    <div style="margin-top:16px;border-top:1px dashed var(--bd);padding-top:12px;">
        <div style="font-size:12.5px;font-weight:700;color:var(--gray);margin-bottom:6px;">
            <?php echo lang() === 'zh' ? '已删除的附件（可恢复）' : 'Deleted attachments (can be restored)'; ?>
        </div>
        <?php foreach ($deletedDocs as $d): ?>
            <div style="display:flex;gap:8px;align-items:center;font-size:12.5px;padding:6px 0;border-bottom:1px solid #f1f3f7;color:var(--gray);">
                <span style="flex:1;"><?php echo h($d['title'] ?: $d['original_name']); ?></span>
                <span><?php echo h($d['deleted_at']); ?></span>
                <form method="post" style="display:inline;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="act" value="doc_del">
                    <input type="hidden" name="doc_id" value="<?php echo (int)$d['id']; ?>">
                    <input type="hidden" name="mode" value="restore">
                    <button class="btn btn-s" type="submit"><i class="fas fa-rotate-left"></i> <?php echo lang() === 'zh' ? '恢复' : 'Restore'; ?></button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- 上传：多选 + 挂节点 + 标题 + 客户可见（AJAX，带进度条；JS 失效时退回普通表单提交） -->
    <form method="post" enctype="multipart/form-data" id="docUploadForm"
          style="margin-top:16px;border-top:1px solid var(--bd);padding-top:14px;">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="act" value="upload">
        <div class="form-row">
            <div><label for="kind">Type</label>
                <select name="kind" id="kind">
                    <option value="notice"><?php echo lang() === 'zh' ? '通知书 / 官方文件 Notice' : 'Notice / official document'; ?></option>
                    <option value="certificate"><?php echo h(t('adm.certificate')); ?></option>
                    <option value="poa"><?php echo h(t('adm.poa')); ?></option>
                    <option value="id_doc"><?php echo h(t('adm.id_doc')); ?></option>
                    <option value="device"><?php echo h(t('adm.device')); ?></option>
                    <option value="other"><?php echo h(t('optional')); ?></option>
                </select></div>
            <div><label for="files"><?php echo lang() === 'zh' ? '文件（可多选）' : 'Files (multiple)'; ?></label>
                <input type="file" id="files" name="files[]" multiple
                       accept=".jpg,.jpeg,.png,.gif,.webp,.avif,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx"></div>
            <?php if ($hasEventCol): ?>
            <div><label for="event_id"><?php echo tp('adm.attach_to_event'); ?></label>
                <select name="event_id" id="event_id">
                    <option value="0"><?php echo h(t('adm.no_step')); ?></option>
                    <?php foreach ($events as $ev): ?>
                        <option value="<?php echo (int)$ev['id']; ?>">
                            <?php echo h(($ev['title_en'] ?: $ev['title_zh']) . ' · ' . $ev['created_at']); ?>
                        </option>
                    <?php endforeach; ?>
                </select></div>
            <?php endif; ?>
            <div><label for="doc_title"><?php echo tp('adm.doc_title'); ?></label>
                <input type="text" id="doc_title" name="doc_title"
                       placeholder="<?php echo lang() === 'zh' ? '如：受理通知书 Acceptance Notice' : 'e.g. Acceptance Notice'; ?>"></div>
            <div><label for="doc_note"><?php echo tp('note'); ?></label>
                <input type="text" id="doc_note" name="doc_note"></div>
            <div><label for="doc_visible"><?php echo tp('adm.doc_visible'); ?></label>
                <select name="doc_visible" id="doc_visible">
                    <option value="1"><?php echo h(t('yes')); ?></option>
                    <option value="0"><?php echo h(t('no')); ?></option>
                </select></div>
        </div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <button class="btn btn-p" type="submit" id="docUploadBtn"><i class="fas fa-upload"></i> <?php echo h(t('upload')); ?></button>
            <span id="docUpState" style="font-size:13px;"></span>
            <span class="lg-sm" style="opacity:.75;font-size:12.5px;">
                <?php echo lang() === 'zh'
                    ? '挂到节点的附件会显示在客户订单页该环节下方；一次选多个文件时，标题会套用到每一个。'
                    : 'Attachments tied to a step appear under that step on the client order page. With multiple files the same title is applied to each.'; ?>
            </span>
        </div>
        <!-- 进度条 + 已选文件清单 -->
        <div id="docUpBarWrap" style="display:none;margin-top:10px;background:#eef2ff;border-radius:6px;height:8px;overflow:hidden;max-width:480px;">
            <div id="docUpBar" style="height:100%;width:0;background:var(--pri);transition:width .15s;"></div>
        </div>
        <div id="docUpList" style="margin-top:8px;font-size:12.5px;color:#4b5563;"></div>
    </form>
</div>

<!-- 客户留言 -->
<?php $awaiting = $msgs && end($msgs)['sender_role'] === 'client'; ?>
<div class="ad-card" data-workspace-tab="messages" data-workspace-label="Messages">
    <h2>
        Messages
        <?php if ($awaiting): ?>
            <span class="ad-tag new" style="margin-left:8px;"><?php echo lang() === 'zh' ? '待回复' : 'Awaiting reply'; ?></span>
        <?php endif; ?>
        <span class="lg-sm" style="font-weight:400;opacity:.7;margin-left:6px;">
            <?php echo lang() === 'zh' ? '客户在 account.php 订单页提交，回复会邮件通知客户。' : 'Posted by the client on account.php; your reply is emailed to them.'; ?>
        </span>
    </h2>

    <?php if (!$msgs): ?>
        <div class="ad-empty"><?php echo lang() === 'zh' ? '暂无留言' : 'No messages yet'; ?></div>
    <?php else: ?>
        <?php $lastId = end($msgs)['id']; reset($msgs); ?>
        <?php foreach ($msgs as $m): ?>
            <div style="border-left:3px solid <?php echo $m['sender_role'] === 'client' ? ($m['id'] === $lastId ? 'var(--bad)' : '#f59e0b') : '#10b981'; ?>;padding:9px 13px;margin-bottom:9px;font-size:13.5px;background:<?php echo $m['sender_role'] === 'client' ? '#fffdf7' : '#f8fafc'; ?>;border-radius:0 8px 8px 0;">
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <?php if ($m['sender_role'] === 'client'): ?>
                        <span class="ad-tag new"><?php echo h($client['name'] ?? 'Client'); ?></span>
                    <?php elseif ($m['sender_role'] === 'admin'): ?>
                        <span class="ad-tag done"><?php echo h($m['admin_name'] ?: 'Admin'); ?></span>
                    <?php else: ?>
                        <span class="ad-tag closed">System</span>
                    <?php endif; ?>
                    <span class="lg-sm" style="opacity:.6;margin-left:auto;"><?php echo h($m['created_at']); ?></span>
                </div>
                <div style="white-space:pre-wrap;margin-top:5px;color:#1f2937;"><?php echo h($m['body']); ?></div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <form method="post" style="margin-top:14px;">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="act" value="reply">
        <div class="form-row">
            <div><label for="reply_body"><?php echo lang() === 'zh' ? '回复客户' : 'Reply to client'; ?></label>
                <textarea id="reply_body" name="body" rows="3" placeholder="<?php echo lang() === 'zh' ? '输入回复内容…' : 'Type your reply…'; ?>"></textarea></div>
        </div>
        <?php echo ad_notify_field(true, 'notify_reply'); ?>
        <button class="btn btn-p" type="submit"><i class="fas fa-paper-plane"></i> <?php echo lang() === 'zh' ? '提交回复' : 'Post reply'; ?></button>
    </form>
</div>

<p><a class="btn btn-s" href="<?php echo h(site_url('app/admin/orders.php')); ?>">← <?php echo h(t('back')); ?></a></p>

<script>
/* 附件 AJAX 上传：与前台 enquiry.php 同一套 XHR + 进度条模式。
 * 成功后整页刷新一次，让新附件按节点正确归组（分组 HTML 由服务端渲染，前端不重复造轮子）。 */
(function () {
    var form  = document.getElementById('docUploadForm');
    if (!form) return;
    var btn   = document.getElementById('docUpBtn') || form.querySelector('button[type="submit"]');
    var state = document.getElementById('docUpState');
    var barW  = document.getElementById('docUpBarWrap');
    var bar   = document.getElementById('docUpBar');
    var list  = document.getElementById('docUpList');
    var files = document.getElementById('files');
    var MAX_MB = <?php echo (int)cfg('storage.max_upload_mb', 16); ?>;
    var MAX    = MAX_MB * 1024 * 1024;
    var ZH = <?php echo lang() === 'zh' ? 'true' : 'false'; ?>;
    var busy = false;

    function fmt(n) {
        n = Number(n) || 0;
        if (n < 1024) return n + ' B';
        if (n < 1048576) return Math.round(n / 1024) + ' KB';
        return (n / 1048576).toFixed(1) + ' MB';
    }
    function say(txt, color) {
        state.textContent = txt;
        state.style.color = color || '';
    }

    /* 选完文件立刻列出清单 + 超限预警 */
    files.addEventListener('change', function () {
        if (!list) return;
        if (!this.files || !this.files.length) { list.innerHTML = ''; return; }
        var html = [], over = 0, total = 0;
        for (var i = 0; i < this.files.length; i++) {
            var f = this.files[i];
            total += f.size;
            if (f.size > MAX) over++;
            html.push((i + 1) + '. ' + f.name + ' · ' + fmt(f.size) + (f.size > MAX ? ' <b style="color:#ef4444;">&gt; ' + MAX_MB + 'MB</b>' : ''));
        }
        if (this.files.length > 1) html.push('— ' + fmt(total) + ' / ' + this.files.length + ' files');
        list.innerHTML = html.join('<br>');
        say(over > 0 ? (ZH ? '有 ' + over + ' 个文件超过 ' + MAX_MB + 'MB，会被拒绝。' : over + ' file(s) exceed ' + MAX_MB + 'MB and will be rejected.') : '', over > 0 ? '#ef4444' : '');
    });

    form.addEventListener('submit', function (e) {
        /* 没选文件 → 让浏览器自己弹必选提示（原生行为），不拦 */
        if (!files.files || !files.files.length) return;
        if (busy) { e.preventDefault(); return; }

        var fd = new FormData();
        /* 不含 act=upload：走专用 JSON 接口，避免整页 POST */
        fd.append('order_id', '<?php echo (int)$id; ?>');
        fd.append('csrf', '<?php echo h(csrf_token()); ?>');
        fd.append('kind', document.getElementById('kind').value);
        var evSel = document.getElementById('event_id');
        if (evSel) fd.append('event_id', evSel.value);
        fd.append('doc_title', document.getElementById('doc_title').value);
        fd.append('doc_note', document.getElementById('doc_note').value);
        fd.append('doc_visible', document.getElementById('doc_visible').value);
        for (var i = 0; i < files.files.length; i++) fd.append('files[]', files.files[i]);

        e.preventDefault();
        busy = true;
        btn.disabled = true;
        say(ZH ? '上传中…' : 'Uploading…', '#6b7280');
        barW.style.display = '';
        bar.style.width = '0%';

        var xhr = new XMLHttpRequest();
        xhr.open('POST', '<?php echo h(site_url("app/admin/upload_doc.php")); ?>', true);
        xhr.upload.onprogress = function (ev) {
            if (ev.lengthComputable) bar.style.width = Math.round(ev.loaded / ev.total * 100) + '%';
        };
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;
            busy = false;
            btn.disabled = false;
            var j = null;
            try { j = JSON.parse(xhr.responseText); } catch (err) {}
            if (xhr.status === 200 && j && j.ok) {
                bar.style.width = '100%';
                say('\u2713 ' + (ZH ? '已上传 ' + j.saved + ' 个文件，正在刷新…' : j.saved + ' file(s) uploaded — refreshing…'), '#10b981');
                setTimeout(function () { location.reload(); }, 800);
            } else {
                barW.style.display = 'none';
                var msg = (j && (j.message || (j.failed && j.failed.join('；')))) || (ZH ? '上传失败（网络或服务器错误）。' : 'Upload failed (network or server error).');
                say('\u2715 ' + msg, '#ef4444');
            }
        };
        xhr.send(fd);
    });
})();
</script>
<?php admin_footer(); ?>
