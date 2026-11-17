<?php
/** 安装初始数据 */
declare(strict_types=1);

function seed(PDO $pdo, string $siteName, array $admin): void
{
    $now = date('Y-m-d H:i:s');

    // 角色
    $roles = [
        ['超级管理员', 'admin', '["*"]', 1],
        ['内容编辑', 'editor', '["articles","pages","categories","faqs","banners","media"]', 0],
        ['预约助理', 'assistant', '["bookings","messages","chat"]', 0],
        ['只读观察', 'viewer', '[]', 0],
    ];
    $st = $pdo->prepare('INSERT INTO roles (name, code, permissions, is_system, created_at) VALUES (?,?,?,?,?)');
    foreach ($roles as $r) { $st->execute([$r[0], $r[1], $r[2], $r[3], $now]); }

    // 管理员
    $st = $pdo->prepare('INSERT INTO users (username, password_hash, display_name, email, role_id, status, created_at) VALUES (?,?,?,?,1,1,?)');
    $st->execute([$admin['username'], password_hash($admin['password'], PASSWORD_DEFAULT), $admin['username'], $admin['email'] ?: null, $now]);

    // 设置
    $settings = [
        ['site.name', $siteName],
        ['site.slogan', '专业 · 保密 · 温暖'],
        ['site.hero_title', '让每一次倾诉，都被温柔以待'],
        ['site.hero_sub', '专业 · 保密 · 温暖 —— 我们在这里，陪你一起面对'],
        ['site.stat_years', ''], ['site.stat_hours', ''], ['site.stat_clients', ''],
        ['seo.description', $siteName . '：提供专业一对一心理咨询，涵盖情绪困扰、亲子关系、职场压力等方向，严格保密，多形式灵活预约。'],
        ['seo.keywords', '心理咨询,心理咨询师,情绪困扰,亲子关系,焦虑抑郁'],
        ['notify.mask_phone', '1'],
        ['security.admin_idle_timeout', '7200'],
        ['license.remote_check', '1'],
        ['stats.enabled', '1'],
    ];
    $st = $pdo->prepare('INSERT INTO settings (skey, svalue, sgroup, updated_at) VALUES (?,?,?,?)');
    foreach ($settings as $s) { $st->execute([$s[0], $s[1], 'general', $now]); }

    // 默认导航
    $navs = [
        ['心理动态', '/news', 'header', 50],
        ['关于我们', '/p/about', 'footer', 10],
        ['收费标准', '/p/pricing', 'footer', 20],
        ['咨询流程', '/p/process', 'footer', 30],
    ];
    $st = $pdo->prepare('INSERT INTO navigations (title, url, location, sort, status) VALUES (?,?,?,1,1)');
    foreach ($navs as $n) { $st->execute([$n[0], $n[1], $n[2]]); }

    // 栏目
    $cats = [
        ['机构动态', 'news-cat', 'news'],
        ['心理科普', 'psychology-101', 'article'],
        ['亲子教育', 'parenting', 'article'],
        ['情绪管理', 'emotion', 'article'],
    ];
    $st = $pdo->prepare('INSERT INTO categories (name, slug, type, status, created_at) VALUES (?,?,?,1,?)');
    foreach ($cats as $c) { $st->execute([$c[0], $c[1], $c[2], $now]); }

    // 单页（含行业合规模板）
    $pages = [
        ['about', '关于我们', "<h2>欢迎来到{$siteName}</h2><p>我们是一支专业的心理咨询团队，致力于为每一位会员提供安全、保密、有温度的心理咨询服务。从这里开始，你不需要独自面对。</p><p>（请在后台「单页管理」中完善本页内容：团队介绍、资质证书、机构理念等。）</p>"],
        ['privacy', '隐私政策', "<h2>隐私政策</h2><p>我们高度重视会员隐私。本站承诺：</p><ul><li>你在预约与留言中提交的信息仅用于安排咨询服务；</li><li>咨询内容严格遵守保密原则，不向任何第三方披露（法律要求的除外）；</li><li>本站不使用第三方广告或追踪脚本，访问统计仅存储匿名化数据。</li></ul><p>（正式使用前请结合机构实际修订本页并咨询法律意见。）</p>"],
        ['consent', '知情同意书', "<h2>咨询知情同意</h2><p>在开始咨询前，请你了解：</p><ul><li>心理咨询是一个合作过程，效果需要时间；</li><li>咨询师会对谈话内容严格保密，但出现自伤、伤人等风险情形时依法负有保密例外；</li><li>你有权随时中止或更换咨询师。</li></ul><p>（正式使用前请结合机构实际修订本页。）</p>"],
        ['pricing', '收费标准', "<p>收费标准请见「咨询服务」各项目页面。首次咨询如需更换咨询师不另收费。（请在后台完善本页）</p>"],
        ['process', '咨询流程', "<ol><li>在线预约或留言</li><li>助理回电确认时间与匹配咨询师</li><li>首次会谈（了解情况、建立目标）</li><li>固定频率咨询（通常每周一次）</li><li>阶段回顾与结束</li></ol>"],
        ['contact', '联系我们', "<p>电话与地址请见页脚。工作时间内来电，通常一小时内回复。</p>"],
    ];
    $st = $pdo->prepare('INSERT INTO pages (title, slug, content, status, show_in_nav, created_at, updated_at) VALUES (?,?,?,?,0,?,?)');
    foreach ($pages as $p) { $st->execute([$p[1], $p[0], $p[2], 'published', $now, $now]); }

    // FAQ 默认（GEO 友好的问答）
    $faqs = [
        ['心理咨询和看医生有什么区别？', '心理咨询主要面向情绪困扰、人际关系、成长议题等心理层面的支持，咨询师不进行医学诊断和开药；如伴随明显躯体症状或疑似精神障碍，我们会建议你先到医院精神科/心理科评估，必要时再配合咨询。'],
        ['咨询内容会被别人知道吗？', '保密是咨询的第一原则。你的谈话内容不会向家人、单位或任何第三方披露。仅在出现自伤/伤人等法律规定的风险情形时，咨询师负有保密例外责任。'],
        ['一次咨询多长时间？一般要做多少次？', '单次通常为 50 分钟。议题较轻的可能数次即可缓解，人格成长类议题通常需要更长时间。首次咨询后咨询师会与你讨论大致计划。'],
        ['预约后可以改期吗？', '可以。请提前 24 小时告知我们，凭预约查询码即可在网站上查询进度，或直接电话联系我们改期。'],
        ['咨询如何收费？', '不同咨询师与项目收费不同，详见「咨询服务」页面。首次会谈中咨询师会与你确认后续频率与费用，无隐藏收费。'],
        ['我不想被人看到我来咨询，怎么办？', '我们提供视频/电话咨询形式，线下预约也支持错峰安排。你在本站填写的信息严格保密，仅用于预约安排。'],
    ];
    $st = $pdo->prepare('INSERT INTO faqs (question, answer, status, sort, created_at) VALUES (?,?,1,?,?)');
    $i = 0;
    foreach ($faqs as $f) { $st->execute([$f[0], '<p>' . $f[1] . '</p>', $i++, $now]); }

    // AI 知识库种子
    $kb = [
        ['你们在哪里？地址是什么？', '我们的地址和接待时间见网站页脚与「联系我们」页面。如果你不方便到店，也可以选择视频或电话咨询哦。', '地址,在哪,位置,怎么走'],
        ['咨询一次多少钱？', '不同咨询师收费不同，一般每次 300-800 元之间，具体见「咨询服务」页面。首次咨询后咨询师会和你确认后续安排。', '多少钱,收费,价格,费用'],
        ['可以预约今晚吗？', '晚间时段部分咨询师可约。你可以在「预约咨询」填写期望时间，助理会尽快确认（通常24小时内）。如果情况紧急，请直接拨打电话联系我们。', '今晚,今天,预约,时间'],
        ['我是家长，孩子不肯上学怎么办？', '孩子拒学背后常有情绪或家庭互动的原因。建议预约「青少年/亲子方向」的咨询师，也可以先在留言里描述一下情况，我们帮你匹配合适的老师。', '孩子,不上学,厌学,青少年,家长'],
    ];
    $st = $pdo->prepare('INSERT INTO ai_kb (question, answer, keywords, source, enabled, created_at) VALUES (?,?,?,?,1,?)');
    foreach ($kb as $k) { $st->execute([$k[0], $k[1], $k[2], 'manual', $now]); }

    // 欢迎文章
    $st = $pdo->prepare(
        'INSERT INTO articles (title, slug, summary, content, author, status, published_at, created_at)
         VALUES (?,?,?,?,?, "published", ?, ?)'
    );
    $st->execute([
        '欢迎来到' . $siteName . '：咨询前的第一份安心',
        'welcome',
        '初次接触心理咨询会有些紧张是很正常的。本文带你了解咨询如何开始、保密原则与预约流程，帮助你安心迈出第一步。',
        '<p>初次接触心理咨询，会紧张、犹豫，都是很正常的。这里有一些你可以先知道的事：</p><h2>咨询如何开始</h2><p>在本站提交预约后，我们的助理会与你确认时间和合适的咨询师。首次会谈主要是了解你的情况、建立咨询目标，你可以说你想说的部分。</p><h2>保密原则</h2><p>你在咨询中谈到的一切都会被严格保密。唯一的例外是涉及你或他人的人身安全风险——这恰恰是为了保护你。</p><h2>常见担忧</h2><p>「我的问题是不是太小了？」——没有太小的问题。愿意面对，本身就是勇敢的开始。</p>',
        $siteName, $now, $now,
    ]);
}
