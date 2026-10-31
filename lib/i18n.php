<?php
/**
 * i18n.php —— 文案层（2026-09-29 起全站英文单语）
 *
 * 历史：本层曾是中英双语对照（t 主语言 / ts 副语言 / tp 对照 HTML）。
 * 现全站（前台+后台+邮件）统一英文，唯一例外：商标分类 / 商品服务**数据**
 * 保留中英双语（最终递交 CNIPA 以中文为准），那部分走数据字段而非本层。
 *
 * 兼容策略：t() / tp() / tj() / enquiry_status_label() / order_status_label()
 * 等调用点全部保留，只是输出恒为英文；lang() 恒返 'en'，
 * 既有的 lang()==='zh' ? … : … 三元全部自动落到英文分支，无需逐处改。
 *
 * 用法：
 *   t('enq.title')                 英文文案
 *   tp('enq.title')                英文（不再附中文小字）
 *   t('mail.x', ['ref' => 'EN-1']) 占位符 {ref} 替换
 */

if (!function_exists('lang_available')) {
    function lang_available()
    {
        return ['en', 'zh'];
    }
}

/** 强制切换语言：已停用（全站英文），保留函数兼容旧调用点 */
if (!function_exists('set_lang')) {
    function set_lang($code)
    {
        return 'en';
    }
}

if (!function_exists('lang')) {
    function lang()
    {
        /* 全站英文（2026-09-29）：不再读 session / cookie / ?lang=。
         * 旧的 cookie（cntm_lang=zh）残留无害，直接忽略。 */
        return 'en';
    }
}

/** 副语言（历史上的对照显示第二语言）：已停用 */
if (!function_exists('sub_lang')) {
    function sub_lang()
    {
        return 'en';
    }
}

/** 加载语言包 */
if (!function_exists('lang_pack')) {
    function lang_pack($code = null)
    {
        static $packs = [];
        if ($code === null) $code = lang();
        if (!isset($packs[$code])) {
            $file = __DIR__ . '/../lang/' . $code . '.php';
            $packs[$code] = is_file($file) ? require $file : [];
        }
        return $packs[$code];
    }
}

/** 翻译（可指定语言，用于按收件人语言发邮件） */
if (!function_exists('t_lang')) {
    function t_lang($key, $vars = [], $code = null)
    {
        $pack = lang_pack($code);
        $s = isset($pack[$key]) ? $pack[$key] : $key;
        if (!empty($vars)) {
            foreach ($vars as $k => $v) {
                $s = str_replace('{' . $k . '}', (string)$v, $s);
            }
        }
        return $s;
    }
}

/** 主语言 */
if (!function_exists('t')) {
    function t($key, $vars = [])
    {
        return t_lang($key, $vars, lang());
    }
}

/** 副语言：已停用（全站英文），恒返空串 —— tp()/tj() 因此只输出英文 */
if (!function_exists('ts')) {
    function ts($key, $vars = [])
    {
        return '';
    }
}

/**
 * 文案输出（历史为中英对照，现恒为英文单语）
 * 例：<span class="lg-lg">Submit enquiry</span>
 */
if (!function_exists('tp')) {
    function tp($key, $vars = [])
    {
        $main = t($key, $vars);
        $sub  = ts($key, $vars);
        if ($sub === '' || $sub === $main) {
            return '<span class="lg-lg">' . h($main) . '</span>';
        }
        return '<span class="lg-lg">' . h($main) . '</span><span class="lg-sm">' . h($sub) . '</span>';
    }
}

/** "中文 / English" 紧凑并列（用于表格头、按钮） */
if (!function_exists('tj')) {
    function tj($key, $vars = [], $sep = ' / ')
    {
        $a = t($key, $vars);
        $b = ts($key, $vars);
        return ($b === '' || $b === $a) ? $a : ($b . $sep . $a);
    }
}

/** 语言切换链接（保留当前 query） */
if (!function_exists('lang_switch_url')) {
    function lang_switch_url($code)
    {
        $params = $_GET;
        $params['lang'] = $code;
        $base = strtok($_SERVER['REQUEST_URI'], '?');
        return h($base . '?' . http_build_query($params));
    }
}

/**
 * 状态枚举 -> 标签（工单）
 * @param string      $s    状态值
 * @param string|null $code 指定语言；null = 跟随当前语言并附中文对照（后台双语），
 *                           传 'en' / 'zh' 则只输出该语言（前台纯英文页用）
 */
if (!function_exists('enquiry_status_label')) {
    function enquiry_status_label($s, $code = null)
    {
        $map = [
            'new' => 'st.enq.new', 'processing' => 'st.enq.processing',
            'quoted' => 'st.enq.quoted',
            'confirmed' => 'st.enq.confirmed', 'declined' => 'st.enq.declined',
            'closed' => 'st.enq.closed',
        ];
        if (!isset($map[$s])) return $s;
        return $code === null ? tj($map[$s]) : t_lang($map[$s], [], $code);
    }
}

/** 状态枚举 -> 标签（订单），用法同上 */
if (!function_exists('order_status_label')) {
    function order_status_label($s, $code = null)
    {
        $map = [
            'pending_payment' => 'st.ord.pending_payment', 'paid' => 'st.ord.paid',
            'filed' => 'st.ord.filed', 'examining' => 'st.ord.examining',
            'published' => 'st.ord.published', 'opposed' => 'st.ord.opposed',
            'registered' => 'st.ord.registered', 'refused' => 'st.ord.refused',
            'withdrawn' => 'st.ord.withdrawn', 'refunded' => 'st.ord.refunded',
            'note' => 'st.ord.note',
        ];
        if (!isset($map[$s])) return $s;
        return $code === null ? tj($map[$s]) : t_lang($map[$s], [], $code);
    }
}
