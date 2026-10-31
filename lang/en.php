<?php
/**
 * 英文语言包（前台默认主语言）
 * 占位符用法：{ref} {name} {amount} —— 由 t() 替换
 */
return [

    /* ---------- common ---------- */
    'save' => 'Save', 'cancel' => 'Cancel', 'confirm' => 'Confirm', 'submit' => 'Submit',
    'back' => 'Back', 'close' => 'Close', 'actions' => 'Actions', 'status' => 'Status',
    'date' => 'Date', 'created' => 'Created', 'updated' => 'Updated',
    'search' => 'Search', 'filter' => 'Filter', 'all' => 'All',
    'remark' => 'Remarks', 'note' => 'Notes', 'total' => 'Total',
    'amount' => 'Amount', 'currency' => 'Currency', 'download' => 'Download',
    'upload' => 'Upload', 'send' => 'Send', 'required' => 'Required',
    'optional' => 'Optional', 'yes' => 'Yes', 'no' => 'No', 'loading' => 'Loading…',
    'name' => 'Name', 'email' => 'Email', 'company' => 'Company',
    'country' => 'Country / Region', 'phone' => 'Phone', 'reference' => 'Reference',
    'detail' => 'Details', 'view' => 'View', 'edit' => 'Edit', 'delete' => 'Delete',
    'copy' => 'Copy', 'client' => 'Client', 'refresh' => 'Refresh',
    'internal_note' => 'Internal note (not visible to the client)',
    'visible_to_client' => 'Visible to the client',
    'select' => 'Please select', 'none' => 'None',

    /* ---------- enquiry page ---------- */
    'enq.title' => 'Free Search & Quote',
    'enq.subtitle' => 'Tell us what you want to register. We will search the register against earlier filings and send you the result, together with a firm quote — no payment is taken at this stage.',
    'enq.subtitle_follow' => 'Tell us which mark is involved and how we can help. We will confirm the details with you, then send a firm quote — no payment is taken at this stage.',
    'enq.step_service' => 'Service required',
    'enq.step_items' => 'Classes & goods',
    'enq.step_mark' => 'Trademark details',
    'enq.step_contact' => 'Your contact details',
    'enq.step_review' => 'Review & submit',
    'enq.service_label' => 'What do you need?',
    'enq.service_hint' => 'Choose the service you are interested in. Registration and search require you to pick classes and goods; for a mark you already own, only the registration number is needed.',
    'enq.reg_no' => 'Registration / application number',
    'enq.reg_no_hint' => 'The number printed on your certificate, or the application number if the mark is still pending.',
    'enq.quote_on_request' => 'Quote on request',
    'enq.price_rule_fixed' => 'Our all-inclusive professional fee — government charges are not billed separately.',
    'enq.price_rule_quote' => 'This service is quoted individually. We will confirm the fee in writing before any payment.',
    'enq.pick_hint' => 'Search the Nice Classification and tick the goods or services you need. You may also paste the shortlist you built in the class search tool.',
    'enq.pick_notsure_bold' => 'Not sure which class you need?',
    'enq.pick_notsure' => 'That is fine. Describe what you sell and how it is used in the box below, and we will identify the appropriate class and items for you at no charge. You can leave this step blank.',
    'enq.search_placeholder' => 'Search goods or services, e.g. "software", "clothing"',
    'enq.selected_empty' => 'No items selected yet.',
    'enq.mark_name' => 'Trademark name',
    'enq.mark_name_hint' => 'The exact word(s) you want to protect, including case and spacing.',
    'enq.mark_type' => 'Mark type',
    'enq.type_word' => 'Word mark',
    'enq.type_device' => 'Device / logo',
    'enq.type_combined' => 'Combined (word + device)',
    'enq.type_sound' => 'Sound mark',
    'enq.type_other' => 'Other',
    'enq.device_file' => 'Device image (optional)',
    'enq.applicant_type' => 'Applicant type',
    'enq.applicant_individual' => 'Individual',
    'enq.applicant_company' => 'Company / organisation',
    'enq.applicant_other' => 'Other',
    'enq.selected_items' => 'Selected items',
    'enq.estimate' => 'Estimated total',
    'enq.estimate_note' => 'Indicative only. The final price is confirmed in writing after we complete the availability search and confirm the class list with you.',
    'enq.price_rule' => 'Each class: first 10 items US$199, then US$10 per additional item. Classes are billed separately.',
    'enq.note_label' => 'Anything else we should know?',
    'enq.note_hint' => 'Target markets, launch date, prior filings, or a similar mark you are aware of.',
    'enq.consent' => 'I have read and agree to the Terms of Service and Privacy Policy, including the transfer of my personal data to China.',
    'enq.submit' => 'Submit enquiry',
    'enq.mailto' => 'Or email us directly',
    'enq.err_no_items' => 'Please select at least one goods or service item.',
    'enq.err_no_regno' => 'Please enter the registration or application number of the mark.',
    'enq.err_no_mark' => 'Please tell us the name of the mark.',
    'enq.err_required' => 'Please complete the required fields with a valid email address.',
    'enq.err_consent' => 'Please confirm that you have read and agree to the Terms of Service and Privacy Policy.',
    'enq.err_rate' => 'Too many submissions from this connection. Please try again later or email us directly.',
    'enq.err_csrf' => 'Your session expired. Please refresh the page and try again.',
    'enq.ok_title' => 'Enquiry received',
    'enq.ok_body' => 'We have received your enquiry and sent a confirmation to your email. We will complete the availability search and come back to you with the search result and a firm quote, usually within one business day.',
    'enq.ok_ref' => 'Your reference',
    'enq.ok_next' => 'What happens next',
    'enq.ok_next_1' => 'We search the CNIPA register and assess conflict risk, including the blind period.',
    'enq.ok_next_2' => 'We send you the search result in writing — what we found and what it means for your mark — together with the confirmed class list and final price.',
    'enq.ok_next_3' => 'Once you accept, we open your account and you can track progress and pay from there.',

    /* ---------- device upload ---------- */
    'enq.device_hint'      => 'If your mark contains a logo or device, upload it here. We will check it against the register as filed.',
    'enq.device_prompt'    => 'Click to choose a file, or drop it here',
    'enq.device_formats'   => 'JPG, PNG, GIF, WEBP or PDF — up to {mb} MB',
    'enq.device_none'      => 'No file was received. Please choose a file and try again.',
    'enq.device_uploading' => 'Uploading…',
    'enq.device_ok'        => 'Uploaded',
    'enq.device_fail'      => 'Upload failed',
    'enq.device_too_large' => 'That file is larger than {mb} MB. Please choose a smaller one.',
    'enq.device_bad_type'  => 'That file type is not supported. Please use JPG, PNG, GIF, WEBP or PDF.',
    'enq.device_server_fail' => 'We could not store that file on our server. Please try again, or email it to us instead.',
    'enq.device_remove'    => 'Remove',
    'enq.device_replace'   => 'Choose a different file',
    'enq.device_pending'   => 'Your image is still uploading. Please wait a moment before submitting.',

    /* ---------- submit progress ---------- */
    'enq.submitting'       => 'Submitting…',
    'enq.prog_title'       => 'Sending your enquiry',
    'enq.prog_1'           => 'Checking your details',
    'enq.prog_2'           => 'Saving your enquiry',
    'enq.prog_3'           => 'Emailing your confirmation',
    'enq.prog_note'        => 'This normally takes a few seconds. Please keep this page open — we are writing to you now.',

    /* ---------- carried over from class-finder.php ---------- */
    'enq.carried_n'        => '{n} goods / services carried over from the class browser.',
    'enq.carried_clear'    => 'Clear',

    /* ---------- enquiry status ---------- */
    'st.enq.new' => 'New enquiry', 'st.enq.processing' => 'In progress',
    'st.enq.quoted' => 'Quoted',
    'st.enq.confirmed' => 'Confirmed', 'st.enq.declined' => 'Declined',
    'st.enq.closed' => 'Closed',

    /* ---------- order status ---------- */
    'st.ord.pending_payment' => 'Awaiting payment', 'st.ord.paid' => 'Paid',
    'st.ord.filed' => 'Filed with CNIPA', 'st.ord.examining' => 'Under examination',
    'st.ord.published' => 'Publication period', 'st.ord.opposed' => 'Opposed',
    'st.ord.registered' => 'Registered', 'st.ord.refused' => 'Refused',
    'st.ord.withdrawn' => 'Voided', 'st.ord.refunded' => 'Refunded',
    'st.ord.note' => 'Note',

    /* ---------- payment ---------- */
    'pay.title' => 'Payment',
    'pay.amount_due' => 'Amount due',
    'pay.paid' => 'Paid in full',
    'pay.partial' => 'Partially paid',
    'pay.paypal' => 'Pay with PayPal',
    'pay.bank' => 'Bank transfer',
    'pay.bank_hint' => 'Transfer to the account below and quote your order reference. We confirm receipt manually, usually within one business day.',
    'pay.bank_beneficiary' => 'Beneficiary', 'pay.bank_name' => 'Bank',
    'pay.bank_ben_addr' => 'Beneficiary address',
    'pay.bank_account' => 'Account number', 'pay.bank_swift' => 'SWIFT / BIC',
    'pay.bank_addr' => 'Bank address',
    /* 汇款附言的标签：只出现在订单页，值恒为订单号 ORD-YYYYMMDD-NNNN
       （next_ref('orders','ORD')）。2026-10-14 曾误标为 'Reference to quote' ——
       那是报价单编号（Q- 前缀）的叫法，订单页根本不会出现Q- 号。
       标签直接写明「订单号 + ORD- 前缀」，客户照填即可，不必再猜。 */
    'pay.bank_note' => 'Order number (quote this in the remittance advice)',
    /* 币种提醒（2026-10-14）：无条件常显，不按订单币种判断。
       我方只有美元订单，但客户手里未必有美元 —— 德国/瑞士客户看到美元报价，
       可能直接汇等额欧元/瑞郎，账号是美元账户会被收款行拒收或退回。
       措辞要点明「你的银行可能主动提议换汇」这个真实场景，客户才会读进去。 */
    'pay.bank_choose_currency' => 'Choose the currency you will send:',
    'pay.bank_cur_note' => 'Please send the payment in the exact currency you select above — each currency has its own account number, and some currencies are accepted only at certain banks (the panel above shows only the bank(s) that accept your chosen currency). China does not use IBAN: leave that field empty and route by SWIFT/BIC plus the account number. Ask your bank to set charges to OUR so we receive the full amount. If your bank offers to convert to your local currency, please decline and send the exact currency shown.',
    /* 防伪造提示（2026-10-14）—— 两次返工后的定稿：
       ① 删掉「核对统一社会信用代码」：USCC 不是 SWIFT 报文字段，汇款单上无处填、
          银行也不会索要，客户没有执行路径 —— 写上去等于没写。
       ② 改成客户真能做到的动作：核对收款人名称；不确定就用**已知渠道**联系我们
          （不是回复那封可疑邮件），且点明「从我们网站/订单页直接看到的」才算数。
       ③ 保留收款人地址那句 —— 这条有实际价值：SWIFT 报文里收款人地址与银行地址
          是两个独立字段，填错会被退回。 */
    'pay.bank_verify_hint' => 'If anything here looks different from what you received from us, please stop and check with us first. The beneficiary name must read exactly as shown above. Only treat these details as genuine if you found them on this page or on our website — if they arrived by email, contact us on our published email address or WhatsApp before sending anything. Please note: if your bank asks for a beneficiary address, use the one shown above, not our bank\'s address.',
    'pay.official_note' => 'The fee above is all-inclusive — the CNIPA official fee is already inside it and is not billed separately. Once the application has been filed, the fee paid for the filing stage is not refundable, whatever the outcome.',
    /* pay.offline_note 于 2026-10-14 删除：原文「本站不提供自动刷卡结账」与事实矛盾 ——
       PayPal 本身即可用信用卡结账，该句无条件显示在付款框底部、
       紧邻 PayPal 按钮，客户会读到「上面能刷卡、下面说不能刷卡」。
       「绝不保存卡号」这一点属实但已无载体承载；如需保留合规声明，
       应放在不与支付选项同框的位置（如付款页脚注或 terms/privacy）。 */
    'pay.receipt' => 'Receipt',
    'pay.paypal_off' => 'Online card payment is not enabled yet. Please use bank transfer, or contact us for a secure payment link.',
    /* 计费币种说明（2026-10-15）：我们所有订单一律以美元计费，但客户手里的钱
       未必是美元 —— 选币种汇出时按汇款当日汇率折算即可，不强求汇美元。
       这句放在「应付金额」正下方，客户一眼能看到，避免「为什么让我汇欧元」的困惑。 */
    'pay.billing_usd_note' => 'All orders are billed in US dollars (USD). If you remit in another currency, please convert the amount using the exchange rate on the day you send the payment.',
    /* 回单上传（2026-10-15）：主打收转账后新增的客户自助动作 —— 客户汇完款
       把银行回单传上来，我们后台核对、确认收款后自动发「已收到款」邮件。 */
    'pay.upload_receipt' => 'Upload your wire receipt',
    'pay.upload_receipt_hint' => 'After sending the transfer, upload a photo or PDF of the bank receipt here. We verify it and confirm receipt, then email you a payment-received notice. This step is optional but speeds up verification.',
    'pay.receipt_file' => 'Bank receipt (image or PDF)',
    'pay.receipt_currency' => 'Currency you sent',
    'pay.receipt_amount' => 'Amount you sent',
    'pay.receipt_status' => 'Status',
    'pay.receipt_pending' => 'Pending verification',
    'pay.receipt_confirmed' => 'Confirmed — payment recorded',
    'pay.receipt_rejected' => 'Rejected — please re-upload or contact us',
    'pay.receipt_submitted' => 'Receipt submitted. We will verify it and email you once confirmed.',
    'pay.receipt_your_submissions' => 'Your submitted receipts',
    'pay.receipt_err_nofile' => 'Please choose a receipt file to upload.',
    'pay.receipt_err_upload' => 'Upload failed: ',
    'pay.receipt_err_order' => 'Order not found.',
    /* 回单上传 UI（2026-10-15 改 AJAX 自动上传）：选文件即自动上传，再填币种/金额提交 */
    'pay.receipt_add_attachment' => 'Add attachment',
    'pay.receipt_uploading' => 'Uploading…',
    'pay.receipt_uploaded' => 'Uploaded',
    'pay.receipt_remove' => 'Remove',
    'pay.receipt_submit' => 'Submit receipt',
    'pay.receipt_need_file' => 'Please add the bank receipt (image or PDF) first.',
    /* 专业汇款信息界面（2026-10-15）：网商银行主推、农行备选，按币种显示中间行 */
    'pay.bank_primary' => 'Recommended',
    'pay.bank_alternative' => 'Alternative',
    'pay.bank_intermediary' => 'Intermediary bank(s) for this currency (Field 54/56A)',
    'pay.bank_wise_hint' => 'You can remit through your local bank, or use Wise (formerly TransferWise) for competitive exchange rates and lower fees. Either way, quote your order number and ask your bank to set charges to OUR so we receive the full amount.',
    /* MT103 报文字段标签：直接标在汇款信息上，客户/银行照抄即可，最专业 */
    'pay.wire_title' => 'International wire transfer information',
    'pay.field59' => 'Field 59 — Beneficiary customer',
    'pay.field57a' => 'Field 57A — Beneficiary bank',
    'pay.field5456a' => 'Field 54/56A — Intermediary bank',

    /* ---------- account / client ---------- */
    'acc.title' => 'My orders',
    'acc.login_title' => 'Access your order',
    /* 2026-09-30：不再是 one-time —— 链接在有效期内可反复打开（见 token_is_reusable()） */
    'acc.login_hint' => 'Enter the email address we used to open your account and we will send you a sign-in link.',
    /* {ttl} 由 token_ttl_text() 填充（默认 "24 hours"），改配置时长不用再动文案 */
    'acc.link_sent' => 'Sign-in link sent. Please check your inbox — it is valid for {ttl}.',
    /* 2026-09-30：可重复消费后「已被使用」这一种失败不会再出现，只可能是过期或链接本身不对
     * （quote.php 也复用这条：那边是报价令牌对不上，同样适用） */
    'acc.link_invalid' => 'This link is invalid or has expired. Please request a new one.',
    'acc.order' => 'Order',
    'acc.service' => 'Service',
    'acc.timeline' => 'Progress',
    'acc.documents' => 'Documents',
    'acc.messages' => 'Messages',
    'acc.logout' => 'Sign out',
    'acc.mark' => 'Mark',
    'acc.mark_type' => 'Mark type',
    'acc.existing_reg_no' => 'Existing reg. no.',
    'acc.classes' => 'Classes',
    'acc.registrant' => 'Registrant',
    'acc.registrant_type' => 'Registrant type',
    'acc.app_no' => 'Application number',
    'acc.empty' => 'No orders yet.',
    'acc.send_msg' => 'Send message',
    'acc.docs_hint' => 'All files are kept here permanently — you can always come back and download them.',
    'acc.docs_empty' => 'Files are listed under their progress steps above ↑',
    'acc.welcome' => 'Welcome back,',
    'acc.signed_in_as' => 'Signed in as',

    /* ---------- admin ---------- */
    'adm.title' => 'Operations console',
    'adm.dashboard' => 'Dashboard', 'adm.enquiries' => 'Enquiries',
    'adm.orders' => 'Orders', 'adm.clients' => 'Clients',
    'adm.payments' => 'Payments', 'adm.settings' => 'Settings',
    'adm.messages' => 'Messages',
    'adm.login' => 'Sign in', 'adm.username' => 'Username',
    'adm.password' => 'Password', 'adm.signin' => 'Sign in',
    'adm.logout' => 'Sign out', 'adm.bad_login' => 'Invalid username or password.',
    'adm.filter_status' => 'Filter by status', 'adm.apply' => 'Apply',
    'adm.mark_name' => 'Mark', 'adm.items' => 'Items', 'adm.classes' => 'Classes',
    'adm.est_price' => 'Estimate', 'adm.final_price' => 'Quoted price',
    'adm.service_type' => 'Service', 'adm.reg_no' => 'Registration no.',
    'adm.not_specified' => 'Not specified', 'adm.complex_case' => 'Complex — quote by agreement',
    'adm.create_quote' => 'Create quote', 'adm.send_quote' => 'Send quote to client',
    'adm.mark_quoted' => 'Mark as quoted', 'adm.create_order' => 'Create order & open client account',
    'adm.add_event' => 'Add progress event', 'adm.sensitive_warn' => 'Sensitive file — access is logged',
    'adm.no_data' => 'No records yet.', 'adm.saved' => 'Saved.', 'adm.sent' => 'Sent.',
    'adm.ref' => 'Ref', 'adm.submitted' => 'Submitted', 'adm.applicant' => 'Applicant',
    'adm.internal' => 'Internal', 'adm.public' => 'Public',
    /* 后台发客户的邮件：勾选才发，不勾则静默处理（2026-09-29） */
    'adm.notify_client' => 'Also email the client',
    'adm.notify_client_hint' => 'Uncheck to save / record this without sending the client an email.',
    'adm.notify_skipped' => 'Saved (no email sent).',
    'adm.device' => 'Device image', 'adm.poa' => 'Power of attorney',
    'adm.id_doc' => 'Identity document', 'adm.certificate' => 'Certificate',
    'adm.quote_sent_ok' => 'Quote sent to the client by email.',
    'adm.order_created_ok' => 'Order created. Client account opened and sign-in link emailed.',
    'adm.confirm_delete' => 'Are you sure you want to delete this?',
    'adm.total_due' => 'Total due', 'adm.paid_amount' => 'Paid',
    'adm.total_price' => 'Total price',
    'adm.mark_name_hint' => 'Multiple marks in one order: separate with commas.',
    'adm.classes_hint' => 'Class numbers only, e.g. 25, 34. Kept items of a class stay when you remove another class.',
    'adm.total_hint' => 'Edit only when the quote changes. Official and translation fees are kept as-is.',
    'adm.payment_method' => 'Method', 'adm.payment_ref' => 'Provider reference',
    'adm.mark_paid' => 'Mark as paid (bank transfer)',
    'adm.receipts' => 'Receipts uploaded by client', 'adm.receipt_declared' => 'Declared by client',
    'adm.confirm_receipt' => 'Confirm received', 'adm.reject_receipt' => 'Reject',
    'adm.reject_reason' => 'Reject reason (internal note)', 'adm.receipt_view' => 'View receipt',
    'adm.new_count' => 'New enquiries', 'adm.today_count' => 'Today',
    'adm.open_orders' => 'Open orders', 'adm.revenue' => 'Received (USD)',

    /* ---------- admin · full CRUD (003) ---------- */
    'adm.users' => 'Team accounts', 'adm.new_user' => 'New account',
    'adm.role' => 'Role', 'adm.role_owner' => 'Owner (full access)',
    'adm.role_staff' => 'Staff', 'adm.role_viewer' => 'Viewer (read-only)',
    'adm.can_view_sensitive' => 'May view sensitive files',
    'adm.new_password' => 'New password', 'adm.keep_password' => 'Leave blank to keep current password',
    'adm.self_edit' => 'You cannot disable or delete your own account.',
    'adm.last_owner' => 'At least one owner account must remain.',
    'adm.username_taken' => 'That username is already taken.',
    'adm.disable' => 'Disable', 'adm.enable' => 'Enable', 'language' => 'Language',

    'adm.new_client' => 'New client', 'adm.client_detail' => 'Client detail',
    'adm.client_note' => 'Internal note (not shown to the client)',
    'adm.new_order' => 'New order', 'adm.create_order_admin' => 'Create order (offline / by email)',
    'adm.order_source' => 'Order source', 'adm.notify_client' => 'Email the client a sign-in link',
    'adm.mark_paid_now' => 'Also record payment now',
    'adm.void_order' => 'Void this order', 'adm.void_reason' => 'Reason for voiding',
    'adm.voided' => 'Voided', 'adm.void_done' => 'Order voided.',
    'adm.new_enquiry' => 'New enquiry', 'adm.merge_into' => 'Merge into enquiry ref',
    'adm.merged' => 'Merged', 'adm.merge_done' => 'Enquiry merged.',
    'adm.refund' => 'Refund', 'adm.refund_amount' => 'Refund amount',
    'adm.refund_note' => 'Refund note', 'adm.refunded' => 'Refunded',
    'adm.refund_done' => 'Refund recorded.', 'adm.export_csv' => 'Export CSV',
    'adm.payment_note' => 'Note', 'adm.partially_refunded' => 'Partly refunded',
    'adm.total_refunded' => 'Refunded (USD)', 'adm.net_received' => 'Net received (USD)',
    'adm.filter' => 'Filter', 'adm.reset' => 'Reset', 'adm.date_from' => 'From', 'adm.date_to' => 'To',
    'adm.confirm_refund' => 'Record this refund? The client will see it on their order page.',
    'adm.attach' => 'Attachments', 'adm.attach_to_event' => 'Attach to progress step',
    'adm.doc_title' => 'Title shown to the client', 'adm.doc_visible' => 'Visible to client',
    'adm.no_step' => '— Not tied to a step —', 'adm.upload_multi' => 'You can select several files at once',
    'adm.activity' => 'Activity log', 'adm.by' => 'by',

    /* ---------- mail subjects ---------- */
    'mail.enq_received_subject' => 'We have received your enquiry {ref}',
    'mail.admin_new_enquiry_subject' => '[New enquiry] {ref} — {name}',
    'mail.quote_subject' => 'Your availability search and quotation {ref} from China Trademark',
    /* 2026-09-30：原标题只说「sign in to track it」，没提付款，客户会当成
     * 单纯的账号通知而漏掉付款。改成 pay and track it，付款这件事在标题就说清。
     * 另加 _paid 版本：后台手工建单并登记已收款时，这封不该再催付款。 */
    'mail.account_subject' => 'Your order {ref} is ready — pay and track it',
    'mail.account_subject_paid' => 'Your order {ref} is confirmed — payment received',
    'mail.payment_subject' => 'Payment received for order {ref}',
    'mail.event_subject' => 'Update on your order {ref}',
];
