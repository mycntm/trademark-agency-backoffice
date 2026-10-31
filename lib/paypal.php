<?php
/**
 * paypal.php —— PayPal 企业收款（REST API · Orders v2）
 *
 * 流程：
 *   1) paypal_create_order()  服务端建单 -> 返回 approval 链接 / order id
 *   2) 客户在 PayPal 页面批准（Smart Buttons 或跳转）
 *   3) paypal_capture()       服务端捕获扣款
 *   4) webhook PAYMENT.CAPTURE.COMPLETED -> paypal_verify_webhook() 验签后更新订单状态
 *
 * ⚠️ 安全要点：
 *   - 金额永远以后端订单为准，绝不相信前端传来的金额。
 *   - capture 必须校验订单状态与金额匹配，且用 provider_ref 做幂等。
 *   - webhook 必须验签（否则任何人都能伪造"支付成功"）。
 */

if (!function_exists('paypal_enabled')) {
    function paypal_enabled()
    {
        $c = cfg('paypal');
        return !empty($c['enabled']) && !empty($c['client_id']) && !empty($c['client_secret']);
    }
}

if (!function_exists('paypal_base')) {
    function paypal_base()
    {
        return !empty(cfg('paypal.sandbox')) ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
    }
}

/** OAuth2 access token（进程内 + 文件缓存，避免每次请求都换 token） */
if (!function_exists('paypal_token')) {
    function paypal_token(&$error = null)
    {
        static $mem = null;
        if ($mem !== null && $mem['exp'] > time() + 30) return $mem['token'];

        $cacheFile = dirname(__DIR__) . '/storage/paypal_token.json';
        if (is_file($cacheFile)) {
            $j = json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($j) && !empty($j['token']) && $j['exp'] > time() + 30) {
                $mem = $j;
                return $j['token'];
            }
        }

        $c = cfg('paypal');
        $ch = curl_init(paypal_base() . '/v1/oauth2/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
            CURLOPT_USERPWD        => $c['client_id'] . ':' . $c['client_secret'],
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Accept-Language: en_US'],
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $res  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($res === false || $code < 200 || $code >= 300) {
            $error = "token request failed (HTTP $code) $cerr";
            app_log('paypal token error: ' . $error, 'ERROR');
            return null;
        }
        $j = json_decode($res, true);
        if (empty($j['access_token'])) {
            $error = 'token missing in response';
            return null;
        }
        $mem = ['token' => $j['access_token'], 'exp' => time() + (int)($j['expires_in'] ?? 3000)];
        @file_put_contents($cacheFile, json_encode($mem));
        return $mem['token'];
    }
}

/** 通用 API 调用 */
if (!function_exists('paypal_api')) {
    function paypal_api($method, $path, $body = null, &$error = null)
    {
        $token = paypal_token($error);
        if (!$token) return null;

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ];
        $ch = curl_init(paypal_base() . $path);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ];
        if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        curl_setopt_array($ch, $opts);
        $res  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($res === false) {
            $error = "curl error: $cerr";
            app_log('paypal api error: ' . $error, 'ERROR');
            return null;
        }
        $j = json_decode($res, true);
        if ($code < 200 || $code >= 300) {
            $error = 'HTTP ' . $code . ' ' . (isset($j['message']) ? $j['message'] : substr($res, 0, 200));
            app_log("paypal api $method $path -> $error", 'ERROR');
            return ['__error' => true, 'code' => $code, 'body' => $j ?: $res];
        }
        return $j;
    }
}

/**
 * 创建 PayPal 订单
 * @param array  $order  orders 表行（含 ref / total / currency）
 * @param string $returnUrl 客户批准后回跳地址
 * @param string $cancelUrl 取消回跳
 */
if (!function_exists('paypal_create_order')) {
    function paypal_create_order($order, $returnUrl, $cancelUrl, &$error = null)
    {
        $amount = number_format((float)$order['total'], 2, '.', '');
        $cur    = $order['currency'] ?: cfg('paypal.currency', 'USD');

        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $order['ref'],
                'description'  => 'Trademark application ' . $order['ref'] . ' — ' . ($order['mark_name'] ?: ''),
                'amount'       => ['currency_code' => $cur, 'value' => $amount],
            ]],
            'application_context' => [
                'brand_name' => cfg('paypal.brand_name', 'EXAMPLE IP'),
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
                'user_action' => 'PAY_NOW',
            ],
        ];

        $j = paypal_api('POST', '/v2/checkout/orders', $payload, $error);
        if (!$j || !empty($j['__error'])) return null;

        $approve = '';
        if (!empty($j['links'])) {
            foreach ($j['links'] as $l) {
                if ($l['rel'] === 'approve') { $approve = $l['href']; break; }
            }
        }
        return ['id' => $j['id'], 'status' => $j['status'] ?? '', 'approve_url' => $approve];
    }
}

/** 捕获扣款 */
if (!function_exists('paypal_capture')) {
    function paypal_capture($paypalOrderId, &$error = null)
    {
        $j = paypal_api('POST', '/v2/checkout/orders/' . rawurlencode($paypalOrderId) . '/capture', null, $error);
        if (!$j || !empty($j['__error'])) return null;

        $captureId = '';
        $amount = null; $cur = '';
        if (!empty($j['purchase_units'][0]['payments']['captures'][0])) {
            $cap = $j['purchase_units'][0]['payments']['captures'][0];
            $captureId = $cap['id'] ?? '';
            $amount = $cap['amount']['value'] ?? null;
            $cur = $cap['amount']['currency_code'] ?? '';
        }
        return [
            'status'     => $j['status'] ?? '',
            'capture_id' => $captureId,
            'amount'     => $amount,
            'currency'   => $cur,
            'raw'        => $j,
        ];
    }
}

/** 查询订单（对账用） */
if (!function_exists('paypal_get_order')) {
    function paypal_get_order($paypalOrderId, &$error = null)
    {
        return paypal_api('GET', '/v2/checkout/orders/' . rawurlencode($paypalOrderId), null, $error);
    }
}

/**
 * Webhook 验签
 * @param array  $headers  请求头（键名用 HTTP_PAYPAL_TRANSMISSION_ID 等 $_SERVER 形式）
 * @param string $rawBody  原始请求体（必须原样，不能 json_decode 后再 encode）
 */
if (!function_exists('paypal_verify_webhook')) {
    function paypal_verify_webhook($headers, $rawBody, &$error = null)
    {
        $get = function ($k) use ($headers) {
            foreach ([$k, 'HTTP_' . strtoupper(str_replace('-', '_', $k))] as $key) {
                if (isset($headers[$key])) return $headers[$key];
            }
            return '';
        };

        $payload = [
            'auth_algo'         => $get('PAYPAL-AUTH-ALGO'),
            'cert_url'          => $get('PAYPAL-CERT-URL'),
            'transmission_id'   => $get('PAYPAL-TRANSMISSION-ID'),
            'transmission_sig'  => $get('PAYPAL-TRANSMISSION-SIG'),
            'transmission_time' => $get('PAYPAL-TRANSMISSION-TIME'),
            'webhook_id'        => cfg('paypal.webhook_id'),
            'webhook_event'     => json_decode($rawBody, true),
        ];
        if (!$payload['transmission_id'] || !$payload['webhook_id']) {
            $error = 'missing webhook headers or webhook_id not configured';
            return false;
        }

        $j = paypal_api('POST', '/v1/notifications/verify-webhook-signature', $payload, $error);
        if (!$j) return false;
        $ok = (isset($j['verification_status']) && $j['verification_status'] === 'SUCCESS');
        if (!$ok) $error = 'verification_status=' . ($j['verification_status'] ?? 'unknown');
        return $ok;
    }
}

/**
 * 幂等登记 webhook 事件（重复事件直接跳过）
 * @return bool true = 需要处理；false = 已处理过
 */
if (!function_exists('webhook_register')) {
    function webhook_register($eventId, $eventType, $rawBody, $verified)
    {
        try {
            $exists = db_val('SELECT id FROM webhook_events WHERE provider="paypal" AND event_id=?', [$eventId], null);
            if ($exists) return false;
            db_exec('INSERT INTO webhook_events (provider,event_id,event_type,payload_json,verified) VALUES ("paypal",?,?,?,?)',
                [$eventId, $eventType, substr($rawBody, 0, 60000), $verified ? 1 : 0]);
            return true;
        } catch (Exception $e) {
            app_log('webhook_register failed: ' . $e->getMessage(), 'ERROR');
            return false;
        }
    }
}

if (!function_exists('webhook_mark_processed')) {
    function webhook_mark_processed($eventId)
    {
        try {
            db_exec('UPDATE webhook_events SET processed=1 WHERE provider="paypal" AND event_id=?', [$eventId]);
        } catch (Exception $e) {
            app_log('webhook_mark_processed failed: ' . $e->getMessage(), 'ERROR');
        }
    }
}
