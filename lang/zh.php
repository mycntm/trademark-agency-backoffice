<?php
/**
 * 中文语言包（后台默认主语言）
 * key 必须与 en.php 完全一一对应，否则 add_sub_lang() 会报缺失。
 */
return [

    /* ---------- common ---------- */
    'save' => '保存', 'cancel' => '取消', 'confirm' => '确认', 'submit' => '提交',
    'back' => '返回', 'close' => '关闭', 'actions' => '操作', 'status' => '状态',
    'date' => '日期', 'created' => '创建时间', 'updated' => '更新时间',
    'search' => '搜索', 'filter' => '筛选', 'all' => '全部',
    'remark' => '备注', 'note' => '说明', 'total' => '合计',
    'amount' => '金额', 'currency' => '币种', 'download' => '下载',
    'upload' => '上传', 'send' => '发送', 'required' => '必填',
    'optional' => '选填', 'yes' => '是', 'no' => '否', 'loading' => '加载中…',
    'name' => '姓名', 'email' => '邮箱', 'company' => '公司',
    'country' => '国家 / 地区', 'phone' => '电话', 'reference' => '编号',
    'detail' => '详情', 'view' => '查看', 'edit' => '编辑', 'delete' => '删除',
    'copy' => '复制', 'client' => '客户', 'refresh' => '刷新',
    'internal_note' => '内部备注（客户不可见）',
    'visible_to_client' => '客户可见',
    'select' => '请选择', 'none' => '无',

    /* ---------- enquiry page ---------- */
    'enq.title' => '提交询价',
    'enq.subtitle' => '告诉我们您要注册的商标。我们先做在先权利检索，与您确认细节后出具正式报价 —— 此阶段不会产生任何付款。',
    'enq.subtitle_follow' => '告诉我们涉及的商标以及需要办理的事项。与您确认细节后出具正式报价 —— 此阶段不会产生任何付款。',
    'enq.step_service' => '所需服务',
    'enq.step_items' => '类别与商品',
    'enq.step_mark' => '商标信息',
    'enq.step_contact' => '联系方式',
    'enq.step_review' => '核对并提交',
    'enq.service_label' => '您需要的服务',
    'enq.service_hint' => '请选择您要办理的业务。注册申请与检索需要挑选类别和商品；已拥有的商标只需提供注册号即可。',
    'enq.reg_no' => '注册号 / 申请号',
    'enq.reg_no_hint' => '商标注册证上的编号；若商标仍在申请中，请填写申请号。',
    'enq.quote_on_request' => '协商定价',
    'enq.price_rule_fixed' => '我方全包专业服务费，官费不再另行收取。',
    'enq.price_rule_quote' => '此项服务需个案定价，我们会在收款前以书面形式确认费用。',
    'enq.pick_hint' => '按尼斯分类检索并勾选需要的商品或服务，也可以直接粘贴在分类检索工具里生成的清单。',
    'enq.pick_notsure_bold' => '不确定该选哪个类别？',
    'enq.pick_notsure' => '没关系。在下面说明您销售的商品及其用途，我们免费为您确定合适的类别和商品项。本步骤可以留空。',
    'enq.search_placeholder' => '搜索商品或服务，例如“软件”“服装”',
    'enq.selected_empty' => '尚未选择任何项目。',
    'enq.mark_name' => '商标名称',
    'enq.mark_name_hint' => '需要保护的确切文字，包括大小写与空格。',
    'enq.mark_type' => '商标类型',
    'enq.type_word' => '文字商标',
    'enq.type_device' => '图形商标',
    'enq.type_combined' => '组合商标（文字+图形）',
    'enq.type_sound' => '声音商标',
    'enq.type_other' => '其他',
    'enq.device_file' => '图样（选填）',
    'enq.applicant_type' => '申请人类型',
    'enq.applicant_individual' => '个人',
    'enq.applicant_company' => '企业 / 组织',
    'enq.applicant_other' => '其他',
    'enq.selected_items' => '已选项目',
    'enq.estimate' => '预估总价',
    'enq.estimate_note' => '仅为预估值。完成在先权利检索并与您确认类别清单后，最终价格以书面报价为准。',
    'enq.price_rule' => '每个类别：前 10 项 199 美元，超出每项加 10 美元；各类别独立计费。',
    'enq.note_label' => '还有其他需要说明的吗？',
    'enq.note_hint' => '目标市场、上市时间、已有申请，或您已知的近似商标。',
    'enq.consent' => '我已阅读并同意《服务条款》与《隐私政策》，包括将我的个人信息传输至中国。',
    'enq.submit' => '提交询价',
    'enq.mailto' => '或直接发邮件给我们',
    'enq.err_no_items' => '请至少选择一个商品或服务项目。',
    'enq.err_no_regno' => '请填写该商标的注册号或申请号。',
    'enq.err_no_mark' => '请填写商标名称。',
    'enq.err_required' => '请填写必填项并提供有效的邮箱地址。',
    'enq.err_consent' => '请确认已阅读并同意《服务条款》与《隐私政策》。',
    'enq.err_rate' => '当前网络提交次数过多，请稍后再试或直接发邮件给我们。',
    'enq.err_csrf' => '会话已过期，请刷新页面后重试。',
    'enq.ok_title' => '询价已收到',
    'enq.ok_body' => '我们已收到您的询价，并向您的邮箱发送了确认邮件。我们会完成在先权利检索后出具正式报价，通常在一个工作日内回复。',
    'enq.ok_ref' => '您的询价编号',
    'enq.ok_next' => '接下来会发生什么',
    'enq.ok_next_1' => '我们检索 CNIPA 商标数据库，并评估包含盲期在内的冲突风险。',
    'enq.ok_next_2' => '我们向您发送书面报价，含确认后的类别清单与最终价格。',
    'enq.ok_next_3' => '您接受报价后，我们为您开通账号，您即可跟踪进度并付款。',

    /* ---------- device upload ---------- */
    'enq.device_hint'      => '若商标含图形要素，请在此上传图样，我们将按图样实际样式进行检索。',
    'enq.device_prompt'    => '点击选择文件，或将文件拖到此处',
    'enq.device_formats'   => 'JPG、PNG、GIF、WEBP 或 PDF，最大 {mb} MB',
    'enq.device_none'      => '未收到文件，请选择文件后重试。',
    'enq.device_uploading' => '正在上传…',
    'enq.device_ok'        => '已上传',
    'enq.device_fail'      => '上传失败',
    'enq.device_too_large' => '文件超过 {mb} MB，请选择更小的文件。',
    'enq.device_bad_type'  => '不支持该文件格式，请使用 JPG、PNG、GIF、WEBP 或 PDF。',
    'enq.device_server_fail' => '服务器无法保存该文件，请重试或直接通过邮件发送给我们。',
    'enq.device_remove'    => '移除',
    'enq.device_replace'   => '重新选择',
    'enq.device_pending'   => '图样仍在上传中，请稍候再提交。',

    /* ---------- submit progress ---------- */
    'enq.submitting'       => '正在提交…',
    'enq.prog_title'       => '正在发送您的询价',
    'enq.prog_1'           => '正在核对您的信息',
    'enq.prog_2'           => '正在保存询价单',
    'enq.prog_3'           => '正在发送确认邮件',
    'enq.prog_note'        => '通常需要几秒钟。请保持本页面打开——我们正在给您写信。',

    /* ---------- 从 class-finder.php（分类检索页）带入 ---------- */
    'enq.carried_n'        => '已从分类检索页带入 {n} 项商品 / 服务。',
    'enq.carried_clear'    => '清空',

    /* ---------- enquiry status ---------- */
    'st.enq.new' => '新询价', 'st.enq.processing' => '处理中',
    'st.enq.quoted' => '已报价',
    'st.enq.confirmed' => '已确认', 'st.enq.declined' => '已拒绝',
    'st.enq.closed' => '已关闭',

    /* ---------- order status ---------- */
    'st.ord.pending_payment' => '待付款', 'st.ord.paid' => '已付款',
    'st.ord.filed' => '已递交商标局', 'st.ord.examining' => '审查中',
    'st.ord.published' => '公告期', 'st.ord.opposed' => '被异议',
    'st.ord.registered' => '已注册', 'st.ord.refused' => '已驳回',
    'st.ord.withdrawn' => '已作废', 'st.ord.refunded' => '已退款',
    'st.ord.note' => '备注',

    /* ---------- payment ---------- */
    'pay.title' => '付款',
    'pay.amount_due' => '应付金额',
    'pay.paid' => '已全额付款',
    'pay.partial' => '部分付款',
    'pay.paypal' => '使用 PayPal 付款',
    'pay.bank' => '银行转账',
    'pay.bank_hint' => '请汇至下列账户并在附言中注明订单编号。我们人工确认到账，通常在一个工作日内完成。',
    'pay.bank_beneficiary' => '收款人', 'pay.bank_name' => '开户行',
    'pay.bank_ben_addr' => '收款人地址',
    'pay.bank_account' => '账号', 'pay.bank_swift' => 'SWIFT / BIC',
    'pay.bank_addr' => '银行地址',
    /* 汇款附言的标签：只出现在订单页，值恒为订单号 ORD-YYYYMMDD-NNNN
       （next_ref('orders','ORD')）。2026-10-14 曾误标为 'Reference to quote' ——
       那是报价单编号（Q- 前缀）的叫法，订单页根本不会出现 Q- 号。
       标签直接写明「订单号 + ORD- 前缀」，客户照填即可，不必再猜。 */
    'pay.bank_note' => '订单编号（请在汇款附言中填写此编号）',
    /* 币种提醒（2026-10-14）：无条件常显，不按订单币种判断。
       我方只有美元订单，但客户手里未必有美元 —— 德国/瑞士客户看到美元报价，
       可能直接汇等额欧元/瑞郎，账号是美元账户会被收款行拒收或退回。
       措辞要点明「你的银行可能主动提议换汇」这个真实场景，客户才会读进去。 */
    'pay.bank_choose_currency' => '请选择您将汇出的币种：',
    'pay.bank_cur_note' => '请严格按上方所选币种汇出——每个币种对应不同账号，且部分币种仅特定银行受理（上方面板只会显示受理该币种的银行）。中国不使用 IBAN：该栏留空，凭 SWIFT/BIC 加账号汇路即可。请要求银行将费用设为 OUR（付款方承担双方费用），确保我们收到全额。若银行提议换成您本币汇出，请拒绝，按所示币种原币汇出。',
    /* 防伪造提示（2026-10-14）—— 两次返工后的定稿：
       ① 删掉「核对统一社会信用代码」：USCC 不是 SWIFT 报文字段，汇款单上无处填、
          银行也不会索要，客户没有执行路径 —— 写上去等于没写。
       ② 改成客户真能做到的动作：核对收款人名称；不确定就用**已知渠道**联系我们
          （不是回复那封可疑邮件），且点明「从我们网站/订单页直接看到的」才算数。
       ③ 保留收款人地址那句 —— 这条有实际价值：SWIFT 报文里收款人地址与银行地址
          是两个独立字段，填错会被退回。 */
    'pay.bank_verify_hint' => '若此处信息与您此前从我方收到的内容不符，请先暂停并与我方核实。收款人名称须与上方完全一致。仅当这些信息是在本页或我方网站上看到的，才可视为准确；若是通过邮件收到的，请在汇款前用我方公布的邮箱或 WhatsApp 联系我们核实。另请注意：若银行要求填写收款人地址，请使用上方所列地址，不要使用开户行地址。',
    'pay.official_note' => '以上费用为全包价——商标局官费已包含在内，不单独列收。申请递交后，该阶段所付费用无论结果如何均不予退还。',
    /* pay.offline_note 于 2026-10-14 删除：原文「本站不提供自动刷卡结账」与事实矛盾 ——
       PayPal 本身即可用信用卡结账，该句无条件显示在付款框底部、
       紧邻 PayPal 按钮，客户会读到「上面能刷卡、下面说不能刷卡」。
       「绝不保存卡号」这一点属实但已无载体承载；如需保留合规声明，
       应放在不与支付选项同框的位置（如付款页脚注或 terms/privacy）。 */
    'pay.receipt' => '收据',
    'pay.paypal_off' => '在线支付功能尚未启用，请使用银行转账，或联系我们获取安全付款链接。',
    /* 计费币种说明（2026-10-15）：所有订单一律以美元计费，客户按汇款当日汇率折算汇出即可。 */
    'pay.billing_usd_note' => '所有订单均以美元（USD）计费。若您以其他币种汇出，请按汇款当日汇率折算金额。',
    /* 回单上传（2026-10-15）：客户汇完款自助上传银行回单，后台核对确认后自动发「已收到款」邮件。 */
    'pay.upload_receipt' => '上传电汇回单',
    'pay.upload_receipt_hint' => '汇款后，请在此上传银行回单的照片或 PDF。我们会核对并在确认收款后给您发送「已收到款」邮件。此步骤可选，但有助于加快核对。',
    'pay.receipt_file' => '银行回单（图片或 PDF）',
    'pay.receipt_currency' => '汇出币种',
    'pay.receipt_amount' => '汇出金额',
    'pay.receipt_status' => '状态',
    'pay.receipt_pending' => '待核对',
    'pay.receipt_confirmed' => '已确认 — 已记录收款',
    'pay.receipt_rejected' => '已驳回 — 请重新上传或联系我们',
    'pay.receipt_submitted' => '回单已提交，核对通过后会邮件通知您。',
    'pay.receipt_your_submissions' => '您已提交的回单',
    'pay.receipt_err_nofile' => '请选择要上传的回单文件。',
    'pay.receipt_err_upload' => '上传失败：',
    'pay.receipt_err_order' => '未找到该订单。',
    /* 回单上传 UI（2026-10-15 改 AJAX 自动上传）：选文件即自动上传，再填币种/金额提交 */
    'pay.receipt_add_attachment' => '添加附件',
    'pay.receipt_uploading' => '上传中…',
    'pay.receipt_uploaded' => '已上传',
    'pay.receipt_remove' => '移除',
    'pay.receipt_submit' => '提交回单',
    'pay.receipt_need_file' => '请先添加银行回单（图片或 PDF）。',
    /* 专业汇款信息界面（2026-10-15）：网商银行主推、农行备选，按币种显示中间行 */
    'pay.bank_primary' => '主推',
    'pay.bank_alternative' => '备选',
    'pay.bank_intermediary' => '该币种对应的中间行（Field 54/56A）',
    'pay.bank_wise_hint' => '您可通过当地银行柜台或网银汇款，也可使用 Wise（原 TransferWise）以更优汇率、更低费用汇出。无论哪种方式，请务必在附言中填写订单编号，并要求银行将费用设为 OUR（付款方承担双方费用），确保我们收到全额。',
    /* MT103 报文字段标签：直接标在汇款信息上，客户/银行照抄即可 */
    'pay.wire_title' => '跨境汇入汇款路径（International Wire Info）',
    'pay.field59' => 'Field 59 — 收款人',
    'pay.field57a' => 'Field 57A — 收款行',
    'pay.field5456a' => 'Field 54/56A — 中间行',

    /* ---------- account / client ---------- */
    'acc.title' => '我的订单',
    'acc.login_title' => '查看您的订单',
    /* 2026-09-30：不再是「一次性」—— 链接在有效期内可反复打开（见 token_is_reusable()） */
    'acc.login_hint' => '请输入我们为您开通账号时使用的邮箱，我们会发送登录链接。',
    /* {ttl} 由 token_ttl_text() 填充（默认 "24 hours"），改配置时长不用再动文案 */
    'acc.link_sent' => '登录链接已发送，请查收邮箱 —— 链接 {ttl} 内有效。',
    /* 2026-09-30：可重复消费后不会再出现「已被使用」，只可能是过期或链接本身不对 */
    'acc.link_invalid' => '该链接无效或已过期，请重新获取。',
    'acc.order' => '订单',
    'acc.service' => '办理事项',
    'acc.timeline' => '进度',
    'acc.documents' => '文件',
    'acc.messages' => '留言',
    'acc.logout' => '退出登录',
    'acc.mark' => '商标',
    'acc.mark_type' => '商标类型',
    'acc.existing_reg_no' => '已有注册号',
    'acc.classes' => '类别',
    'acc.registrant' => '注册人',
    'acc.registrant_type' => '注册人主体类型',
    'acc.app_no' => '申请号',
    'acc.empty' => '暂无订单。',
    'acc.send_msg' => '发送留言',
    'acc.docs_hint' => '全部文件长期保存，随时可以回来下载。',
    'acc.docs_empty' => '文件都挂在各进度节点下方 ↑',
    'acc.welcome' => '欢迎回来，',
    'acc.signed_in_as' => '当前登录账号',

    /* ---------- admin ---------- */
    'adm.title' => '运营管理后台',
    'adm.dashboard' => '概览', 'adm.enquiries' => '询价工单',
    'adm.orders' => '订单', 'adm.clients' => '客户',
    'adm.payments' => '付款', 'adm.settings' => '设置',
    'adm.messages' => '留言',
    'adm.login' => '登录', 'adm.username' => '用户名',
    'adm.password' => '密码', 'adm.signin' => '登录',
    'adm.logout' => '退出', 'adm.bad_login' => '用户名或密码错误。',
    'adm.filter_status' => '按状态筛选', 'adm.apply' => '应用',
    'adm.mark_name' => '商标', 'adm.items' => '项目数', 'adm.classes' => '类别数',
    'adm.est_price' => '预估价', 'adm.final_price' => '报价金额',
    'adm.service_type' => '办理事项', 'adm.reg_no' => '注册号 / 申请号',
    'adm.not_specified' => '未填写', 'adm.complex_case' => '疑难案件 — 协商定价',
    'adm.create_quote' => '生成报价', 'adm.send_quote' => '向客户发送报价',
    'adm.mark_quoted' => '标记为已报价', 'adm.create_order' => '生成订单并开通客户账号',
    'adm.add_event' => '添加进度节点', 'adm.sensitive_warn' => '敏感文件 —— 访问将被记录',
    'adm.no_data' => '暂无记录。', 'adm.saved' => '已保存。', 'adm.sent' => '已发送。',
    'adm.ref' => '编号', 'adm.submitted' => '提交时间', 'adm.applicant' => '申请人',
    'adm.internal' => '内部', 'adm.public' => '公开',
    /* 后台发客户的邮件：勾选才发，不勾则静默处理（2026-09-29） */
    'adm.notify_client' => '同时发邮件通知客户',
    'adm.notify_client_hint' => '取消勾选则只记录、不给客户发邮件。',
    'adm.notify_skipped' => '已保存（未发送邮件）。',
    'adm.device' => '商标图样', 'adm.poa' => '委托书',
    'adm.id_doc' => '身份证件', 'adm.certificate' => '注册证',
    'adm.quote_sent_ok' => '报价已通过邮件发送给客户。',
    'adm.order_created_ok' => '订单已生成，客户账号已开通并已邮件发送登录链接。',
    'adm.confirm_delete' => '确定要删除吗？',
    'adm.total_due' => '应收合计', 'adm.paid_amount' => '已收',
    'adm.total_price' => '总价',
    'adm.mark_name_hint' => '一单多个商标时用逗号分隔。',
    'adm.classes_hint' => '只填类别号，如 25、34。删掉某个类别时，其余类别的商品项保留。',
    'adm.total_hint' => '报价变更时才改；官费与翻译费保持不变。',
    'adm.payment_method' => '支付方式', 'adm.payment_ref' => '支付方流水号',
    'adm.mark_paid' => '标记为已收款（银行转账）',
    'adm.receipts' => '客户上传的回单', 'adm.receipt_declared' => '客户申报',
    'adm.confirm_receipt' => '确认已收款', 'adm.reject_receipt' => '驳回',
    'adm.reject_reason' => '驳回原因（内部备注）', 'adm.receipt_view' => '查看回单',
    'adm.new_count' => '新询价', 'adm.today_count' => '今日',
    'adm.open_orders' => '进行中订单', 'adm.revenue' => '已收款（美元）',

    /* ---------- admin · full CRUD (003) ---------- */
    'adm.users' => '后台账号', 'adm.new_user' => '新建账号',
    'adm.role' => '角色', 'adm.role_owner' => '负责人（全部权限）',
    'adm.role_staff' => '员工', 'adm.role_viewer' => '只读',
    'adm.can_view_sensitive' => '可查看敏感文件',
    'adm.new_password' => '新密码', 'adm.keep_password' => '留空表示不改密码',
    'adm.self_edit' => '不能停用或删除自己正在使用的账号。',
    'adm.last_owner' => '至少要保留一个负责人账号。',
    'adm.username_taken' => '该用户名已被占用。',
    'adm.disable' => '停用', 'adm.enable' => '启用', 'language' => '语言',

    'adm.new_client' => '新建客户', 'adm.client_detail' => '客户详情',
    'adm.client_note' => '内部备注（客户看不到）',
    'adm.new_order' => '新建订单', 'adm.create_order_admin' => '手工建单（线下 / 邮件来的单子）',
    'adm.order_source' => '订单来源', 'adm.notify_client' => '给客户发邮件（含登录链接）',
    'adm.mark_paid_now' => '同时登记为已收款',
    'adm.void_order' => '作废该订单', 'adm.void_reason' => '作废原因',
    'adm.voided' => '已作废', 'adm.void_done' => '订单已作废。',
    'adm.new_enquiry' => '新建询价', 'adm.merge_into' => '合并到工单号',
    'adm.merged' => '已合并', 'adm.merge_done' => '工单已合并。',
    'adm.refund' => '退款', 'adm.refund_amount' => '退款金额',
    'adm.refund_note' => '退款备注', 'adm.refunded' => '已退款',
    'adm.refund_done' => '退款已登记。', 'adm.export_csv' => '导出 CSV',
    'adm.payment_note' => '备注', 'adm.partially_refunded' => '部分退款',
    'adm.total_refunded' => '已退款（美元）', 'adm.net_received' => '净收入（美元）',
    'adm.filter' => '筛选', 'adm.reset' => '重置', 'adm.date_from' => '起始日期', 'adm.date_to' => '截止日期',
    'adm.confirm_refund' => '确认登记这笔退款？客户会在订单页看到。',
    'adm.attach' => '附件', 'adm.attach_to_event' => '挂到进度节点',
    'adm.doc_title' => '给客户看的标题', 'adm.doc_visible' => '对客户可见',
    'adm.no_step' => '— 不挂节点 —', 'adm.upload_multi' => '可一次选择多个文件',
    'adm.activity' => '操作留痕', 'adm.by' => '操作人',

    /* ---------- mail subjects ---------- */
    'mail.enq_received_subject' => '我们已收到您的询价 {ref}',
    'mail.admin_new_enquiry_subject' => '【新询价】{ref} — {name}',
    'mail.quote_subject' => '您的报价单 {ref} — China Trademark',
    /* 2026-09-30：同 en.php —— 标题补上付款；另加已付清版本 */
    'mail.account_subject' => '您的订单 {ref} 已建立 — 可付款并跟踪进度',
    'mail.account_subject_paid' => '您的订单 {ref} 已确认 — 款项已收到',
    'mail.payment_subject' => '订单 {ref} 已收到付款',
    'mail.event_subject' => '订单 {ref} 进度更新',
];
