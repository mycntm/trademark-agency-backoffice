<?php
/**
 * mailer.php —— SMTP 发信（自实现，不依赖 PHPMailer / composer）
 *
 * 支持：SSL(465) / STARTTLS(587) / 明文(25，仅内网)
 * 认证：AUTH LOGIN / AUTH PLAIN（按服务器 EHLO 返回的列表自动选择）
 * 发送失败会写入 mail_queue，可用 app/cron/mail_queue.php 重投。
 *
 * ⚠️ 上线前必须在 DNS 配置 SPF、DKIM、DMARC，否则自动邮件极易进垃圾箱。
 */

if (!function_exists('mime_header_encode')) {
    function mime_header_encode($s)
    {
        if ($s === '') return '';
        // 纯 ASCII 且无特殊字符则原样输出
        if (!preg_match('/[^\x20-\x7E]/', $s) && strpbrk($s, '=?()<>@,;:\\".[]') === false) {
            return $s;
        }
        return '=?UTF-8?B?' . base64_encode($s) . '?=';
    }
}

/** 构造完整 MIME 邮件（multipart/alternative） */
if (!function_exists('build_mime')) {
    function build_mime($from, $fromName, $to, $subject, $html, $text, $replyTo = '')
    {
        $boundary = '=_b_' . md5(uniqid('', true));
        $eol = "\r\n";

        $head = [];
        $head[] = 'From: ' . mime_header_encode($fromName) . ' <' . $from . '>';
        $head[] = 'To: <' . $to . '>';
        if ($replyTo !== '') $head[] = 'Reply-To: ' . $replyTo;
        $head[] = 'Subject: ' . mime_header_encode($subject);
        $head[] = 'Date: ' . date('r');
        $head[] = 'Message-ID: <' . md5(uniqid('', true)) . '@' . (parse_url(cfg('site_url'), PHP_URL_HOST) ?: 'example.com') . '>';
        $head[] = 'MIME-Version: 1.0';
        $head[] = 'X-Mailer: CNTM-OrderSystem/1.0';
        $head[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

        $body = '';
        $body .= '--' . $boundary . $eol;
        $body .= 'Content-Type: text/plain; charset=UTF-8' . $eol;
        $body .= 'Content-Transfer-Encoding: base64' . $eol . $eol;
        $body .= chunk_split(base64_encode($text), 76, $eol) . $eol;

        $body .= '--' . $boundary . $eol;
        $body .= 'Content-Type: text/html; charset=UTF-8' . $eol;
        $body .= 'Content-Transfer-Encoding: base64' . $eol . $eol;
        $body .= chunk_split(base64_encode($html), 76, $eol) . $eol;

        $body .= '--' . $boundary . '--' . $eol;

        return implode($eol, $head) . $eol . $eol . $body;
    }
}

/**
 * 极简 SMTP 客户端
 * $log（可选）：传一个数组进来，会记录完整握手过程（凭据自动打码），用于后台 SMTP 自检页。
 */
if (!function_exists('smtp_send')) {
    function smtp_send($to, $mimeMessage, &$error = null, &$log = null)
    {
        $c = cfg('smtp');
        // 会话日志：dir = C(发出) / S(收到) / E(错误) / I(提示)
        $lg = function ($dir, $txt) use (&$log) {
            if (is_array($log)) $log[] = [$dir, (string)$txt];
        };
        if (empty($c['enabled'])) { $error = 'SMTP disabled'; $lg('E', 'SMTP is not enabled in config.php (smtp.enabled = false)'); return false; }

        $host = $c['host'];
        $port = (int)$c['port'];
        $secure = strtolower($c['secure'] ?? '');
        $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $timeout = (int)($c['timeout'] ?: 15);

        $lg('I', 'Target ' . $remote . ' (secure=' . ($secure === '' ? 'none' : $secure) . ', timeout ' . $timeout . 's)');

        $errNo = 0; $errStr = '';
        $fp = @stream_socket_client($remote, $errNo, $errStr, $timeout, STREAM_CLIENT_CONNECT);
        if (!$fp) {
            $error = "connect failed: $errStr ($errNo)";
            $lg('E', 'Connect failed: ' . $errStr . " (errno $errNo) — usual causes: outbound port blocked by the host (25/465/587), DNS failure, or a firewall");
            return false;
        }
        $lg('S', 'TCP connection established');
        stream_set_timeout($fp, $timeout);

        $read = function () use ($fp, $lg) {
            $data = '';
            while (($line = fgets($fp, 515)) !== false) {
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') break;  // 末行 "250 xxx"
            }
            $lg('S', trim($data));
            return $data;
        };
        // $mask = true：命令含凭据，日志打码
        $cmd = function ($str, $expect = null, $mask = false) use ($fp, $read, &$error, $lg) {
            $lg('C', $mask ? '**** (credentials hidden)' : $str);
            fwrite($fp, $str . "\r\n");
            $res = $read();
            if ($expect !== null && strpos($res, (string)$expect) !== 0) {
                $error = "unexpected reply to $str: " . trim($res);
                $lg('E', 'Expected a reply starting with ' . $expect . ', got instead: ' . $error);
                return false;
            }
            return $res;
        };

        $read();                                   // 220 greeting
        $ehlo = $cmd('EHLO ' . (parse_url(cfg('site_url'), PHP_URL_HOST) ?: 'localhost'), 250);
        if ($ehlo === false) { fclose($fp); return false; }

        // STARTTLS（587）
        if ($secure === 'tls') {
            if (strpos($ehlo, 'STARTTLS') === false) { $error = 'server does not advertise STARTTLS'; $lg('E', 'Server does not advertise STARTTLS — switch to secure=ssl with port 465'); fclose($fp); return false; }
            if (!$cmd('STARTTLS', 220)) { fclose($fp); return false; }
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                $error = 'TLS negotiation failed'; $lg('E', 'TLS negotiation failed (certificate or protocol mismatch)'); fclose($fp); return false;
            }
            $lg('I', 'TLS enabled — re-sending EHLO');
            $ehlo = $cmd('EHLO ' . (parse_url(cfg('site_url'), PHP_URL_HOST) ?: 'localhost'), 250);
            if ($ehlo === false) { fclose($fp); return false; }
        }

        // AUTH
        $supportsLogin = stripos($ehlo, 'AUTH') !== false && stripos($ehlo, 'LOGIN') !== false;
        $supportsPlain = stripos($ehlo, 'AUTH') !== false && stripos($ehlo, 'PLAIN') !== false;
        $lg('I', 'Server capabilities: ' . ($supportsLogin ? 'AUTH LOGIN ' : '') . ($supportsPlain ? 'AUTH PLAIN' : '') . (!$supportsLogin && !$supportsPlain ? 'no AUTH advertised (sending unauthenticated)' : ''));
        if ($supportsLogin) {
            $cmd('AUTH LOGIN', 334);
            if (!$cmd(base64_encode($c['user']), 334, true)) { $lg('E', 'Username rejected — check that smtp.user is the full email address'); fclose($fp); return false; }
            if (!$cmd(base64_encode($c['pass']), 235, true)) { $lg('E', 'Password rejected — most providers need an app / authorisation code, not the login password'); fclose($fp); return false; }
            $lg('I', 'Authenticated');
        } elseif ($supportsPlain) {
            $auth = base64_encode("\0" . $c['user'] . "\0" . $c['pass']);
            if (!$cmd('AUTH PLAIN ' . $auth, 235, true)) { $lg('E', 'AUTH PLAIN failed — check smtp.user / smtp.pass'); fclose($fp); return false; }
            $lg('I', 'Authenticated');
        } else {
            // 服务器不要求认证（仅内网中继）
        }

        if (!$cmd('MAIL FROM: <' . $c['from'] . '>', 250)) { $lg('E', 'Sender rejected — check that smtp.from belongs to the authenticated mailbox (many providers require a match)'); fclose($fp); return false; }
        if (!$cmd('RCPT TO: <' . $to . '>', 250)) { $lg('E', 'Recipient rejected — the address does not exist or is restricted by the provider'); fclose($fp); return false; }
        if (!$cmd('DATA', 354)) { fclose($fp); return false; }

        // DATA：转义行首句点
        $msg = str_replace("\r\n.", "\r\n..", $mimeMessage);
        if (!preg_match("/\r\n$/", $msg)) $msg .= "\r\n";
        fwrite($fp, $msg);
        $lg('C', '(message body: ' . strlen($msg) . ' bytes)');
        if (!$cmd('.', 250)) { $lg('E', 'Message body rejected — the content may have triggered an anti-spam rule'); fclose($fp); return false; }

        $cmd('QUIT', null);
        $lg('I', 'Message handed over to the SMTP server');
        fclose($fp);
        return true;
    }
}

/**
 * 入队一封邮件（不立即发送）。返回 mail_queue.id
 * send_now = true 时立即尝试发送，失败留在队列等待重投。
 */
if (!function_exists('queue_mail')) {
    function queue_mail($toEmail, $toName, $subject, $html, $text = '', $opts = [])
    {
        $lang = isset($opts['lang']) ? $opts['lang'] : lang();
        $relatedType = isset($opts['related_type']) ? $opts['related_type'] : 'other';
        $relatedId = isset($opts['related_id']) ? (int)$opts['related_id'] : null;

        $id = 0;
        try {
            $id = (int)db_exec(
                'INSERT INTO mail_queue (to_email,to_name,subject,body_html,body_text,lang,status,related_type,related_id)
                 VALUES (?,?,?,?,?,?, "queued", ?, ?)',
                [$toEmail, $toName, $subject, $html, $text, $lang, $relatedType, $relatedId]
            );
        } catch (Exception $e) {
            app_log('queue_mail insert failed: ' . $e->getMessage(), 'ERROR');
        }

        if (!empty($opts['send_now'])) {
            send_queued_mail($id);
        }
        return $id;
    }
}

/** 发送队列中指定一封；返回 bool */
if (!function_exists('send_queued_mail')) {
    function send_queued_mail($id)
    {
        $row = db_one('SELECT * FROM mail_queue WHERE id = ?', [(int)$id]);
        if (!$row) return false;

        $c = cfg('smtp');
        $mime = build_mime(
            $c['from'], $c['from_name'], $row['to_email'],
            $row['subject'], $row['body_html'], $row['body_text'], $c['reply_to']
        );

        $err = null;
        $ok = false;
        try {
            $ok = smtp_send($row['to_email'], $mime, $err);
        } catch (Exception $e) {
            $err = $e->getMessage();
        }

        try {
            if ($ok) {
                db_exec('UPDATE mail_queue SET status="sent", attempts=attempts+1, sent_at=NOW(), last_error="" WHERE id=?', [$id]);
            } else {
                db_exec('UPDATE mail_queue SET status="failed", attempts=attempts+1, last_error=? WHERE id=?',
                    [substr((string)$err, 0, 250), $id]);
                app_log("mail #$id to {$row['to_email']} failed: $err", 'ERROR');
            }
        } catch (Exception $e) {
            app_log('mail status update failed: ' . $e->getMessage(), 'ERROR');
        }
        return $ok;
    }
}

/** cron：重投失败邮件 */
if (!function_exists('flush_mail_queue')) {
    function flush_mail_queue($limit = 20, $maxAttempts = 5)
    {
        $rows = db_all('SELECT id FROM mail_queue WHERE status IN ("queued","failed") AND attempts < ? ORDER BY id ASC LIMIT ' . (int)$limit,
            [$maxAttempts]);
        $sent = 0;
        foreach ($rows as $r) {
            if (send_queued_mail((int)$r['id'])) $sent++;
        }
        return ['attempted' => count($rows), 'sent' => $sent];
    }
}

/* =====================================================================
 *  业务邮件模板（2026-09-29 起全部英文，不再按收件人语言出中文版；
 *  历史中文分支保留为死代码，$lang 恒为 'en' 使其永不命中）
 * ===================================================================== */

if (!function_exists('mail_layout')) {
    function mail_layout($title, $bodyHtml)
    {
        $site = cfg('site_url');
        $nameEn = cfg('site_name_en');
        /* 页头品牌两行（2026-10-04）：第一行品牌 lockup（与站点副标题一致），
           第二行公司法定全名。硬编码品牌行而非走 cfg()：config.php 在 .gitignore、
           不随文件部署；法定全名仍取 cfg('site_name_en')，页脚也保留一份。 */
        return '<!doctype html><html><body style="margin:0;padding:0;background:#f5f7fa;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">'
            . '<div style="max-width:620px;margin:0 auto;padding:24px;">'
            . '<div style="background:#0b3d91;border-radius:10px 10px 0 0;padding:22px 26px;color:#fff;">'
            . '<div style="font-size:16px;font-weight:600;">EXAMPLE IP | China Trademark Specialist</div>'
            . '<div style="font-size:12px;opacity:.85;margin-top:4px;">' . h($nameEn) . '</div>'
            . '</div>'
            . '<div style="background:#fff;padding:26px;border:1px solid #e5e9f0;border-top:none;">'
            . '<h2 style="margin:0 0 16px;font-size:19px;color:#111827;">' . $title . '</h2>'
            . $bodyHtml
            . '</div>'
            . '<div style="padding:16px 26px;color:#6b7280;font-size:12px;line-height:1.7;">'
            . h($nameEn) . '<br>' . h($site)
            . '</div></div></body></html>';
    }
}

if (!function_exists('mail_rows')) {
    function mail_rows($rows)
    {
        $h = '<table style="width:100%;border-collapse:collapse;font-size:14px;margin:14px 0;">';
        foreach ($rows as $k => $v) {
            $h .= '<tr><td style="padding:8px 0;color:#6b7280;width:38%;vertical-align:top;">' . h($k) . '</td>'
                . '<td style="padding:8px 0;color:#111827;">' . h($v) . '</td></tr>';
        }
        return $h . '</table>';
    }
}

/** 客户：询价已收到 */
if (!function_exists('mail_enquiry_received')) {
    function mail_enquiry_received($enq)
    {
        $lang = 'en';   // 全站英文：不再按 $enq['lang'] 识别收件人语言（2026-09-29）
        $T = function ($k, $v = []) use ($lang) { return t_lang($k, $v, $lang); };
        $subject = $T('mail.enq_received_subject', ['ref' => $enq['ref']]);

        // 业务类型：决定邮件里显示哪些字段
        $svcKey  = isset($enq['service_type']) ? (string)$enq['service_type'] : '';
        $svcItem = $svcKey !== '' ? svc_one($svcKey) : null;
        $mode    = svc_mode($svcItem);
        $svcName = $svcItem ? ($lang === 'zh' ? $svcItem['zh'] : $svcItem['en']) : '';
        $regNo   = isset($enq['existing_reg_no']) ? (string)$enq['existing_reg_no'] : '';

        $estTxt = ((float)$enq['est_price'] > 0)
            ? money($enq['est_price'], $enq['currency'])
            : ($lang === 'zh' ? '协商定价' : 'Quote on request');

        if ($lang === 'zh') {
            $rows = ['编号' => $enq['ref']];
            if ($svcName !== '') $rows['办理事项'] = $svcName;
            if ($mode === 'regno') {
                $rows['注册号 / 申请号'] = $regNo;
            } else {
                $rows['商标名称'] = $enq['mark_name'];
            }
            if ($mode === 'classes') {
                $rows['类别数'] = $enq['class_count'];
                $rows['商品/服务项目数'] = $enq['items_count'];
            }
            $rows['预估总价'] = $estTxt . '（预估）';

            $intro = ($mode === 'classes')
                ? '我们已收到您的商标询价，正在安排在先权利检索。以下是询价信息：'
                : '我们已收到您的询价，正在核对所述商标与办理事项。以下是询价信息：';
            $body = '<p>您好 ' . h($enq['name']) . '，</p>'
                . '<p>' . $intro . '</p>'
                . mail_rows($rows)
                . '<p style="background:#fff7ed;border-left:3px solid #f59e0b;padding:12px 14px;color:#92400e;">'
                . '预估价仅为参考。与您确认细节后，最终价格以书面报价为准。此阶段我们不会向您收取任何费用。</p>'
                . '<p>我们通常在<b>一个工作日内</b>回复正式报价。</p>';
        } else {
            $rows = ['Reference' => $enq['ref']];
            if ($svcName !== '') $rows['Service'] = $svcName;
            if ($mode === 'regno') {
                $rows['Registration / application no.'] = $regNo;
            } else {
                $rows['Mark'] = $enq['mark_name'];
            }
            if ($mode === 'classes') {
                $rows['Classes'] = $enq['class_count'];
                $rows['Items']   = $enq['items_count'];
            }
            $rows['Estimated total'] = $estTxt . ' (estimate)';

            $intro = ($mode === 'classes')
                ? 'Thank you — we have received your enquiry and are scheduling the availability search. Here is what we recorded:'
                : 'Thank you — we have received your enquiry and are checking the mark and the service you asked for. Here is what we recorded:';
            $body = '<p>Hi ' . h($enq['name']) . ',</p>'
                . '<p>' . $intro . '</p>'
                . mail_rows($rows)
                . '<p style="background:#fff7ed;border-left:3px solid #f59e0b;padding:12px 14px;color:#92400e;">'
                . 'The figure above is an <b>estimate only</b>. The final price is confirmed in writing once we have agreed the details with you. <b>No payment is taken at this stage.</b></p>'
                . '<p>You will normally receive the search result and a firm quote within <b>one business day</b>.</p>';
        }

        $html = mail_layout(h($subject), $body);
        $text = strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n\n"], $body));
        queue_mail($enq['email'], $enq['name'], $subject, $html, $text, [
            'lang' => $lang, 'related_type' => 'enquiry', 'related_id' => $enq['id'], 'send_now' => true,
        ]);
    }
}

/** 客户：报价已出具（含确认链接） */
if (!function_exists('mail_quote_to_client')) {
    function mail_quote_to_client($enq, $quote, $quoteUrl)
    {
        $lang = 'en';   // 全站英文：不再按 $enq['lang'] 识别收件人语言（2026-09-29）
        $T = function ($k, $v = []) use ($lang) { return t_lang($k, $v, $lang); };
        $subject = $T('mail.quote_subject', ['ref' => $quote['ref']]);
        $money = function ($n) use ($quote) { return money($n, $quote['currency']); };

        // 对外口径：一口价。官费不单独列示（内部拆账仍保留在 quotes 表里，仅后台可见）
        $allInclusive = (float)$quote['official_fee'] + (float)$quote['service_fee'] + (float)$quote['translation_fee'];
        $rows = [];
        $rows[$lang === 'zh'
            ? '全包专业服务费（含商标局官费、我方服务费、翻译与制文费；官费不另行列收）'
            : 'All-inclusive professional fee (CNIPA official fee, our professional fee, translation and documentation — no separate official fee)']
            = $money($allInclusive);
        $extras = json_decode((string)$quote['extras_json'], true);
        if (is_array($extras)) {
            foreach ($extras as $e) {
                $rows[$e['label'] ?? ($lang === 'zh' ? '附加服务' : 'Extra')] = $money($e['amount'] ?? 0);
            }
        }
        $rows[$lang === 'zh' ? '合计' : 'Total'] = $money($quote['total']);

        if ($lang === 'zh') {
            $body = '<p>您好 ' . h($enq['name']) . '，</p>'
                . '<p>感谢您的询价。我们已完成检索，报价如下：</p>'
                . mail_rows($rows)
                . '<p style="background:#fff7ed;border-left:3px solid #f59e0b;padding:12px 14px;color:#92400e;">'
                . '以上费用为全包价——商标局官费已包含在内，不单独列收。申请递交后，该阶段所付费用无论审查结果如何均不予退还。报价有效期至 <b>' . h($quote['valid_until']) . '</b>。</p>'
                . '<p><a href="' . h($quoteUrl) . '" style="display:inline-block;background:#0b3d91;color:#fff;padding:12px 22px;border-radius:7px;text-decoration:none;">查看并确认报价</a></p>';
        } else {
            $body = '<p>Hi ' . h($enq['name']) . ',</p>'
                . '<p>Thank you for your enquiry. We have completed the availability search on your mark. Our quotation is below:</p>'
                . mail_rows($rows)
                . '<p style="background:#fff7ed;border-left:3px solid #f59e0b;padding:12px 14px;color:#92400e;">'
                . 'The fee above is all-inclusive — the CNIPA official fee is already inside it and is not billed separately. Once the application has been filed, the fee paid for the filing stage is not refundable, whatever the examination outcome. This quotation is valid until <b>' . h($quote['valid_until']) . '</b>.</p>'
                . '<p><a href="' . h($quoteUrl) . '" style="display:inline-block;background:#0b3d91;color:#fff;padding:12px 22px;border-radius:7px;text-decoration:none;">View and accept this quotation</a></p>';
        }

        $html = mail_layout(h($subject), $body);
        $text = strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n\n"], $body));
        queue_mail($enq['email'], $enq['name'], $subject, $html, $text, [
            'lang' => $lang, 'related_type' => 'quote', 'related_id' => $quote['id'], 'send_now' => true,
        ]);
    }
}

/** 客户：订单已建立 + 登录链接 */
if (!function_exists('mail_account_ready')) {
    /**
     * 客户：订单已建立 / 账号已开通
     *
     * 2026-09-30：$orderUrl 换成「直达这一单」的链接 —— 客户点开即自动登录，
     * 直接落在订单详情页，付款按钮就在眼前（以前是先登录到账户首页，
     * 客户还得自己找是哪一单）。正文和按钮都改成了以付款为主角的说法。
     *
     * 链接在有效期内可反复打开（2026-09-30 起，见 token_is_reusable()）：
     * 邮件安全网关会自动访问链接做扫描，若是一次性的，客户点开就只剩「已被使用」。
     * 这句说明由 token_link_note() 统一渲染，改配置不用改文案。
     */
    function mail_account_ready($order, $client, $orderUrl)
    {
        $lang = 'en';   // 全站英文：不再按 $client['lang'] 识别收件人语言（2026-09-29）
        $T = function ($k, $v = []) use ($lang) { return t_lang($k, $v, $lang); };

        /* 已付清的订单不能再催付款（2026-09-30）
         * 后台手工建单时可以一步到位勾「已收款」：order_new.php 会先走 apply_payment()
         * 发出「付款已收到」，再调本函数发这一封 —— 两封都 send_now，客户先收到
         * 「钱收到了」再收到「请付款」，自相矛盾。
         * 这里按订单实际收付状态分流：已付清就说确认，未付清才讲付款。 */
        $total   = (float)$order['total'];
        $paid    = (float)$order['paid_amount'];
        $settled = $total > 0 && $paid >= $total - 0.001;

        $subject = $settled
            ? $T('mail.account_subject_paid', ['ref' => $order['ref']])
            : $T('mail.account_subject', ['ref' => $order['ref']]);

        // 有效期 + 能用几次的说明，两版都要带（文案由 token_link_note() 统一出）
        $linkNoteZh = '<p style="color:#6b7280;font-size:13px;">' . h(token_link_note('zh')) . '</p>';
        $linkNoteEn = '<p style="color:#6b7280;font-size:13px;">' . h(token_link_note('en')) . '</p>';
        $btn = function ($label) use ($orderUrl) {
            return '<p><a href="' . h($orderUrl) . '" style="display:inline-block;background:#0b3d91;color:#fff;padding:12px 22px;border-radius:7px;text-decoration:none;">'
                . h($label) . '</a></p>';
        };

        if ($lang === 'zh') {
            if ($settled) {
                $body = '<p>您好，</p>'
                    . '<p>您的商标申请订单已建立，款项已收到，账号也一并为您开通。点击下方按钮即可打开订单页跟踪进度——链接会自动为您登录：</p>'
                    . $btn('查看订单')
                    . $linkNoteZh
                    . mail_rows(['订单号' => $order['ref'], '商标' => $order['mark_name'], '注册人' => $order['registrant_name'],
                                 '金额' => money($total, $order['currency']), '状态' => '已收款']);
            } else {
                $body = '<p>您好，</p>'
                    . '<p>您的商标申请订单已建立，账号已为您开通。点击下方按钮即可直接打开订单页付款——链接会自动为您登录：</p>'
                    . $btn('查看订单并付款')
                    . $linkNoteZh
                    . mail_rows(['订单号' => $order['ref'], '商标' => $order['mark_name'], '注册人' => $order['registrant_name'],
                                 '应付金额' => money($total, $order['currency'])]);
            }
        } else {
            if ($settled) {
                $body = '<p>Hello,</p>'
                    . '<p>Your trademark order has been created and your payment is confirmed. An account has been opened for you — the button below opens the order and signs you in automatically, so you can follow progress as it happens.</p>'
                    . $btn('View order')
                    . $linkNoteEn
                    . mail_rows(['Order' => $order['ref'], 'Mark' => $order['mark_name'], 'Registrant' => $order['registrant_name'],
                                 'Amount' => money($total, $order['currency']), 'Status' => 'Paid']);
            } else {
                $body = '<p>Hello,</p>'
                    . '<p>Your trademark order has been created. The button below opens it directly and signs you in automatically, so you can pay right away by bank transfer (T/T) using the details on that page.</p>'
                    . $btn('View order &amp; pay')
                    . $linkNoteEn
                    . mail_rows(['Order' => $order['ref'], 'Mark' => $order['mark_name'], 'Registrant' => $order['registrant_name'],
                                 'Amount due' => money($total, $order['currency'])]);
            }
        }

        $html = mail_layout(h($subject), $body);
        $text = strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n\n"], $body));
        queue_mail($client['email'], $client['name'], $subject, $html, $text, [
            'lang' => $lang, 'related_type' => 'order', 'related_id' => $order['id'], 'send_now' => true,
        ]);
    }
}

/** 客户：付款已收到 */
if (!function_exists('mail_payment_received')) {
    function mail_payment_received($order, $client, $amount)
    {
        $lang = 'en';   // 全站英文：不再按 $client['lang'] 识别收件人语言（2026-09-29）
        $T = function ($k, $v = []) use ($lang) { return t_lang($k, $v, $lang); };
        $subject = $T('mail.payment_subject', ['ref' => $order['ref']]);
        $body = $lang === 'zh'
            ? '<p>您好，</p><p>我们已收到订单 ' . h($order['ref']) . ' 的付款 ' . h(money($amount, $order['currency'])) . '，感谢！我们将尽快向商标局递交申请。</p>'
                . mail_rows(['订单号' => $order['ref'], '已收' => money($order['paid_amount'], $order['currency']), '应收' => money($order['total'], $order['currency'])])
            : '<p>Hello,</p><p>We have received your payment of ' . h(money($amount, $order['currency'])) . ' for order ' . h($order['ref']) . '. Thank you — we will file the application with the CNIPA shortly.</p>'
                . mail_rows(['Order' => $order['ref'], 'Received' => money($order['paid_amount'], $order['currency']), 'Total' => money($order['total'], $order['currency'])]);

        $html = mail_layout(h($subject), $body);
        $text = strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n\n"], $body));
        queue_mail($client['email'], $client['name'], $subject, $html, $text, [
            'lang' => $lang, 'related_type' => 'payment', 'related_id' => $order['id'], 'send_now' => true,
        ]);
    }
}

/** 客户：进度更新 */
if (!function_exists('mail_order_event')) {
    function mail_order_event($order, $client, $titleEn, $titleZh, $note)
    {
        $lang = 'en';   // 全站英文：不再按 $client['lang'] 识别收件人语言（2026-09-29）
        $T = function ($k, $v = []) use ($lang) { return t_lang($k, $v, $lang); };
        $subject = $T('mail.event_subject', ['ref' => $order['ref']]);
        $title = $lang === 'zh' ? ($titleZh ?: $titleEn) : ($titleEn ?: $titleZh);
        $body = '<p>' . h($title) . '</p>'
            . ($note ? '<p style="color:#4b5563;">' . nl2br(h($note)) . '</p>' : '')
            . '<p><a href="' . h(site_url('account.php')) . '" style="color:#0b3d91;">' . h($lang === 'zh' ? '查看订单详情' : 'View order details') . '</a></p>';
        $html = mail_layout(h($subject), $body);
        $text = strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n\n"], $body));
        queue_mail($client['email'], $client['name'], $subject, $html, $text, [
            'lang' => $lang, 'related_type' => 'order', 'related_id' => $order['id'], 'send_now' => true,
        ]);
    }
}

/** 我们：新询价通知（2026-10-17：改为「全部详细信息」，不再只给摘要） */
if (!function_exists('mail_admin_new_enquiry')) {
    function mail_admin_new_enquiry($enq, $detailUrl)
    {
        $name = $enq['name'] ?: '-';
        $subject = t_lang('mail.admin_new_enquiry_subject', ['ref' => $enq['ref'], 'name' => $name], 'en');

        /* 业务类型决定要显示哪些字段（与后台工单详情页口径一致） */
        $svcKey  = isset($enq['service_type']) ? (string)$enq['service_type'] : '';
        $svcItem = $svcKey !== '' ? svc_one($svcKey) : null;
        $mode    = svc_mode($svcItem);
        $svcName = $svcItem
            ? ((string)$svcItem['en'] . ($svcItem['zh'] !== '' ? ' / ' . (string)$svcItem['zh'] : ''))
            : 'Not specified';
        $regNo   = isset($enq['existing_reg_no']) ? trim((string)$enq['existing_reg_no']) : '';
        $est     = (float)$enq['est_price'];

        /* ---------- 1) 联系人与事项 ---------- */
        $rows = [
            'Reference'      => $enq['ref'],
            'Service'        => $svcName . ($svcKey !== '' ? ' (' . $svcKey . ')' : ''),
        ];
        if ($svcItem && $svcItem['price'] !== null) {
            $rows['List price'] = svc_price_text($svcItem);
        }
        $rows['Client']         = $enq['name'] . ' <' . $enq['email'] . '>';
        $rows['Phone']          = $enq['phone'] !== '' ? $enq['phone'] : '—';
        $rows['Company']        = $enq['company'] !== '' ? $enq['company'] : '—';
        $rows['Country']        = $enq['country'] !== '' ? $enq['country'] : '—';
        $rows['Applicant type'] = ucfirst((string)($enq['applicant_type'] ?: '—'));
        if ($mode === 'regno') {
            $rows['Registration / application no.'] = $regNo !== '' ? $regNo : '—';
        } else {
            $rows['Mark'] = ($enq['mark_name'] !== '' ? $enq['mark_name'] : '—')
                . ' (' . (string)($enq['mark_type'] ?: '—') . ')';
        }
        if ($mode === 'classes') {
            $rows['Classes / items'] = (int)$enq['class_count'] . ' classes / ' . (int)$enq['items_count'] . ' items';
        }
        $rows['Estimate']       = $est > 0 ? money($est, $enq['currency']) . ' (estimate)' : 'Quote on request';
        $rows['Submitted']      = (string)$enq['created_at'];
        $rows['Terms accepted'] = !empty($enq['consent_terms'])
            ? (string)($enq['consent_at'] ?: 'yes')
            : 'Not recorded';

        $body = '<p style="margin:0 0 4px;"><b>' . h($enq['ref']) . '</b> · a new enquiry has been submitted. Full details below — no need to open the admin panel just to read it.</p>'
            . mail_rows($rows);

        /* ---------- 2) 类别与商品/服务项目（逐个列出） ---------- */
        if ($mode === 'classes') {
            $byClass = classes_normalize(isset($enq['classes_json']) ? $enq['classes_json'] : '');
            if ($byClass) {
                $body .= '<div style="margin:22px 0 0;">'
                    . '<div style="font-size:12px;font-weight:700;color:#6b7280;letter-spacing:.06em;text-transform:uppercase;margin-bottom:10px;">Classes and goods / services</div>';
                foreach ($byClass as $cls => $items) {
                    $n = count($items);
                    $body .= '<div style="margin:0 0 14px;padding:12px 14px;background:#f8fafc;border:1px solid #e5e9f0;border-radius:8px;">'
                        . '<div style="font-size:13.5px;font-weight:700;color:#0b3d91;margin-bottom:6px;">Class ' . (int)$cls
                        . ' <span style="font-weight:400;color:#9ca3af;">· ' . $n . ' item' . ($n === 1 ? '' : 's') . '</span></div>'
                        . '<div style="font-size:13.5px;line-height:1.8;color:#374151;">';
                    $lastGroup = '';
                    foreach ($items as $it) {
                        if ($it['grp'] !== '' && $it['grp'] !== $lastGroup) {
                            $groupLabel = 'Similar-goods group ' . h($it['grp']);
                            if ($it['grp_name'] !== '') $groupLabel .= ' · ' . h($it['grp_name']);
                            $body .= '<div style="margin-top:5px;font-weight:700;color:#4a5fc1;">' . $groupLabel . '</div>';
                            $lastGroup = $it['grp'];
                        }
                        $nm = $it['name'] !== '' ? $it['name'] : $it['en'];
                        $en = ($it['name'] !== '' && $it['en'] !== '' && $it['en'] !== $it['name'])
                            ? ' <span style="color:#9ca3af;">/ ' . h($it['en']) . '</span>' : '';
                        $code = $it['code'] !== '' ? '<span style="color:#9ca3af;">' . h($it['code']) . ' · </span>' : '';
                        $body .= $code . '• ' . h($nm) . $en . '<br>';
                    }
                    $body .= '</div></div>';
                }
                $body .= '</div>';
            }
        }

        /* ---------- 3) 客户留言 ---------- */
        if (trim((string)$enq['note']) !== '') {
            $body .= '<div style="margin:22px 0 0;">'
                . '<div style="font-size:12px;font-weight:700;color:#6b7280;letter-spacing:.06em;text-transform:uppercase;margin-bottom:8px;">Client note</div>'
                . '<div style="background:#f8fafc;border-left:3px solid #0b3d91;border-radius:4px;padding:12px 14px;'
                . 'font-size:13.5px;line-height:1.8;color:#111827;white-space:pre-wrap;">'
                . nl2br(h((string)$enq['note'])) . '</div></div>';
        }

        /* ---------- 4) 图样 / 附件 ---------- */
        if (!empty($enq['device_file_id'])) {
            $doc = db_one('SELECT id, original_name, mime, size FROM documents WHERE id = ?', [(int)$enq['device_file_id']]);
            if ($doc) {
                $body .= '<div style="margin:22px 0 0;">'
                    . '<div style="font-size:12px;font-weight:700;color:#6b7280;letter-spacing:.06em;text-transform:uppercase;margin-bottom:8px;">Attached artwork / file</div>'
                    . '<div style="font-size:13.5px;line-height:1.75;color:#111827;">'
                    . '<span style="font-weight:600;word-break:break-all;">' . h((string)$doc['original_name']) . '</span>'
                    . '<span style="color:#9ca3af;"> · ' . h((string)$doc['mime']) . ' · '
                    . number_format((int)$doc['size'] / 1024, 0) . ' KB</span>'
                    . '<br><span style="color:#9ca3af;font-size:12.5px;">Available in the admin panel → enquiry attachment.</span>'
                    . '</div></div>';
            }
        }

        /* ---------- 5) 来源技术信息（排查用，弱化展示） ---------- */
        $tech = [];
        if (!empty($enq['source']))  $tech['Source']        = (string)$enq['source'];
        if (!empty($enq['lang']))    $tech['Page language'] = (string)$enq['lang'];
        if (!empty($enq['ip']))      $tech['IP']            = (string)$enq['ip'];
        if (!empty($enq['ua']))      $tech['User agent']    = mb_substr((string)$enq['ua'], 0, 180);
        if ($tech) {
            $body .= '<div style="margin:20px 0 0;font-size:11.5px;color:#9ca3af;">'
                . '<div style="letter-spacing:.06em;text-transform:uppercase;font-weight:700;margin-bottom:4px;">Submission detail</div>'
                . '<div style="line-height:1.7;">'
                . implode('<br>', array_map(function ($k, $v) {
                    return h($k) . ': ' . h($v);
                }, array_keys($tech), $tech))
                . '</div></div>';
        }

        /* ---------- 6) 动作（仅保留给客户的 mailto；不再含任何后台链接） ---------- */
        $body .= '<p style="margin:22px 0 0;">'
            . '<a href="mailto:' . h($enq['email']) . '?subject=' . rawurlencode('Re: ' . $enq['ref'] . ' — your China trademark enquiry')
            . '" style="display:inline-block;border:1px solid #0b3d91;color:#0b3d91;padding:9px 17px;border-radius:6px;text-decoration:none;">Reply to client</a>'
            . '</p>';

        $html = mail_layout(h($subject), $body);
        $text = strip_tags(str_replace(['<br>', '</p>', '<div'], ["\n", "\n\n", "\n"], $body));
        queue_mail(cfg('company_email'), 'EXAMPLE IP Admin', $subject, $html, $text, [
            'lang' => 'en', 'related_type' => 'enquiry', 'related_id' => $enq['id'], 'send_now' => true,
        ]);
    }
}

/** 我们：客户在订单页留言，通知管理员处理 */
if (!function_exists('mail_admin_new_message')) {
    function mail_admin_new_message(array $m)
    {
        // $m: ref / client_name / client_email / body / url / source
        $subject = 'Client message · ' . $m['ref'] . ' · ' . ($m['client_name'] ?: '-');
        $rows = [
            'Reference'     => $m['ref'],
            'Client'        => ($m['client_name'] ?: '-') . ' <' . $m['client_email'] . '>',
            'Source'        => $m['source'],
            'Message'       => $m['body'],
        ];
        $bodyHtml = '<p>A client has left a message on their order — please reply soon:</p>' . mail_rows($rows)
            . '<p><a href="' . h($m['url']) . '" style="display:inline-block;background:#0b3d91;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;">Open &amp; reply</a></p>';
        $html = mail_layout(h($subject), $bodyHtml);
        $text = strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n\n"], $bodyHtml));
        queue_mail(cfg('company_email'), 'EXAMPLE IP Admin', $subject, $html, $text, [
            'lang' => 'en', 'related_type' => 'other', 'send_now' => true,
        ]);
    }
}

/** 管理员：客户在订单页上传了电汇回单，待核对（2026-10-15） */
if (!function_exists('mail_admin_new_receipt')) {
    function mail_admin_new_receipt($orderId, $currency, $declared)
    {
        $o = db_one('SELECT * FROM orders WHERE id=?', [(int)$orderId]);
        if (!$o) return;
        $subject = 'Wire receipt uploaded · ' . $o['ref'];
        $rows = [
            'Reference' => $o['ref'],
            'Client'    => ($o['client_name'] ?? '') . ' <' . ($o['client_email'] ?? '') . '>',
            'Declared'  => ($currency ?: 'USD') . ' ' . number_format((float)$declared, 2),
        ];
        $bodyHtml = '<p>A client has uploaded a wire receipt on their order — please verify it in the admin panel:</p>'
            . mail_rows($rows)
            . '<p><a href="' . h(site_url('app/admin/order.php?id=' . (int)$orderId)) . '" style="display:inline-block;background:#0b3d91;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;">Open order &amp; verify</a></p>';
        $html = mail_layout(h($subject), $bodyHtml);
        $text = strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n\n"], $bodyHtml));
        queue_mail(cfg('company_email'), 'EXAMPLE IP Admin', $subject, $html, $text, [
            'lang' => 'en', 'related_type' => 'order', 'related_id' => (int)$orderId, 'send_now' => true,
        ]);
    }
}

/** 管理员：客户在线签署了服务合同（2026-10-15） */
if (!function_exists('mail_admin_contract_signed')) {
    function mail_admin_contract_signed($orderId, $signedName)
    {
        $o = db_one('SELECT * FROM orders WHERE id=?', [(int)$orderId]);
        if (!$o) return;
        $c = db_one('SELECT * FROM clients WHERE id=?', [(int)$o['client_id']]);
        $cliName  = $c ? (($c['company'] !== '' ? $c['company'] : $c['name'])) : '';
        $cliEmail = $c ? (string)$c['email'] : '';
        $subject = 'Contract signed · ' . $o['ref'];
        $rows = [
            'Reference'   => $o['ref'],
            'Client'      => ($cliName !== '' ? $cliName . ' ' : '') . '<' . $cliEmail . '>',
            'Signed by'   => $signedName !== '' ? $signedName : '—',
        ];
        $bodyHtml = '<p>A client has signed the service contract online — the signed copy is on the order page:</p>'
            . mail_rows($rows)
            . '<p><a href="' . h(site_url('app/admin/order.php?id=' . (int)$orderId)) . '" style="display:inline-block;background:#0b3d91;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;">Open order</a></p>';
        $html = mail_layout(h($subject), $bodyHtml);
        $text = strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n\n"], $bodyHtml));
        queue_mail(cfg('company_email'), 'EXAMPLE IP Admin', $subject, $html, $text, [
            'lang' => 'en', 'related_type' => 'order', 'related_id' => (int)$orderId, 'send_now' => true,
        ]);
    }
}

/** 客户：我们的工作人员回复了你的留言 */
if (!function_exists('mail_client_admin_reply')) {
    function mail_client_admin_reply($order, $client, $replyBody)
    {
        $lang = 'en';   // 全站英文：不再按 $client['lang'] 识别收件人语言（2026-09-29）
        $T = function ($k, $v = []) use ($lang) { return t_lang($k, $v, $lang); };
        $subject = $T('mail.event_subject', ['ref' => $order['ref']]);
        $body = '<p>' . h($lang === 'zh' ? '我们在你的订单下回复了留言：' : 'We have replied to your message on your order:') . '</p>'
            . '<div style="margin:14px 0;padding:14px 16px;background:#f8fafc;border-left:3px solid #0b3d91;'
            . 'border-radius:4px;font-size:14px;line-height:1.75;color:#111827;white-space:pre-wrap;">' . h($replyBody) . '</div>'
            . '<p><a href="' . h(site_url('account.php')) . '" style="color:#0b3d91;">'
            . h($lang === 'zh' ? '查看并继续对话' : 'View and continue the conversation') . '</a></p>';
        $html = mail_layout(h($subject), $body);
        $text = strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n\n"], $body));
        queue_mail($client['email'], $client['name'], $subject, $html, $text, [
            'lang' => $lang, 'related_type' => 'order', 'related_id' => $order['id'], 'send_now' => true,
        ]);
    }
}
