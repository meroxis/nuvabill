<?php

// Kurdish (Sorani) versions of the built-in email templates. Placeholders stay exactly as in English.
return [
    'client.welcome' => ['بەخێربێیت بۆ {{ company.name }}', <<<'MD'
سڵاو {{ client.first_name }}،

سوپاس بۆ دروستکردنی هەژمار لە {{ company.name }}.

هەر کاتێک بچۆ ژوورەوە بۆ داواکردنی خزمەتگوزاری، دانی پسوولە و پەیوەندی بە پشتگیرییەوە:

[بچۆ بۆ هەژمارەکەت]({{ client_area_url }})

ئەگەر هێشتا وشەی نهێنیت هەڵنەبژاردووە، لە پەڕەی چوونەژوورەوە «وشەی نهێنیم بیرچووە» بەکاربهێنە.

سوپاس،
{{ company.name }}
MD],
    'client.two_factor_code' => ['کۆدی چوونەژوورەوەت بۆ {{ company.name }}', <<<'MD'
سڵاو {{ client.first_name }}،

کۆدی چوونەژوورەوەت ئەمەیە: **{{ code }}**

بۆ 10 خولەک کار دەکات. هەرگیز ئەم کۆدە بە کەس مەدە: ستافەکەمان هەرگیز داوای ناکەن.

ئەگەر تۆ هەوڵی چوونەژوورەوەت نەداوە، ئێستا وشەی نهێنییەکەت بگۆڕە.

{{ company.name }}
MD],
    'order.confirmation' => ['داواکاری ژمارە {{ order.number }} وەرگیرا', <<<'MD'
سڵاو {{ client.first_name }}،

سوپاس بۆ داواکارییەکەت **ژمارە {{ order.number }}**. کۆی گشتی **{{ order.total }}** ـە.

[پسوولەی {{ invoice.number }} بدە]({{ invoice.url }})

هەر کە پارەکە گەیشت خزمەتگوزارییەکەت ئامادە دەکەین و زانیارییەکانت بە ئیمەیڵ بۆ دەنێرین.

سوپاس،
{{ company.name }}
MD],
    'invoice.created' => ['پسوولەی {{ invoice.number }} ئامادەیە', <<<'MD'
سڵاو {{ client.first_name }}،

پسوولەی **{{ invoice.number }}** بە بڕی **{{ invoice.total }}** ئامادەیە. کاتی دانی **{{ invoice.due_date }}** ـە.

[پسوولەکە ببینە و بیدە]({{ invoice.url }})

سوپاس،
{{ company.name }}
MD],
    'invoice.credit_note' => ['پسوولەی گەڕاندنەوەی {{ credit_note.number }} بۆ پسوولەی {{ invoice.number }}', <<<'MD'
سڵاو {{ client.first_name }}،

پسوولەی گەڕاندنەوەی **{{ credit_note.number }}** بە بڕی **{{ credit_note.total }}** بۆ پسوولەی **{{ invoice.number }}** دەرکرد.

{{ credit_note.note }}

[بینینی پسوولە و پسوولەی گەڕاندنەوە]({{ invoice.url }})

{{ company.name }}
MD],
    'invoice.payment_received' => ['پارەی پسوولەی {{ invoice.number }} وەرگیرا', <<<'MD'
سڵاو {{ client.first_name }}،

پارەی پسوولەی **{{ invoice.number }}** مان وەرگرت. سوپاس!

[پسوولەکە ببینە]({{ invoice.url }})

{{ company.name }}
MD],
    'invoice.autopay_upcoming' => ['پسوولەی {{ invoice.number }} لە {{ charge_date }} خۆکار دەدرێت', <<<'MD'
سڵاو {{ client.first_name }}،

لە **{{ charge_date }}** بڕی **{{ invoice.balance }}** بۆ پسوولەی **{{ invoice.number }}** لە {{ payment_method.name }} دەبڕین. پێویست ناکات هیچ بکەیت.

[پسوولەکە ببینە]({{ invoice.url }})

بۆ بەکارهێنانی کارتێکی تر یان ڕاگرتنی پارەدانی خۆکار، [ڕێگاکانی پارەدان]({{ payment_methods_url }}) بکەرەوە.

{{ company.name }}
MD],
    'invoice.autopay_failed' => ['نەمانتوانی پارەی پسوولەی {{ invoice.number }} لە {{ payment_method.name }} ببڕین', <<<'MD'
سڵاو {{ client.first_name }}،

ئەمڕۆ هەوڵماندا **{{ invoice.balance }}** بۆ پسوولەی **{{ invoice.number }}** لە {{ payment_method.name }} ببڕین، بەڵام سەرکەوتوو نەبوو: {{ failure }} هیچ پارەیەک لە هەژمارەکەت نەبڕدرا.

{{ next_try }}

[پسوولەکە بدە]({{ invoice.url }})

ئەگەر کارتەکەت بەسەرچووە یان ژمارەیەکی نوێی هەیە، [کارتە نوێیەکە زیاد بکە]({{ payment_methods_url }}) و لە هەوڵی داهاتوودا بەکاری دەهێنین.

{{ company.name }}
MD],
    'payment.method_expiring' => ['کارتە پاشەکەوتکراوەکەت بەم زووانە بەسەردەچێت', <<<'MD'
سڵاو {{ client.first_name }}،

{{ payment_method.name }} ـەکەت، کە نوێکردنەوەکانت خۆکار دەدات، لە کۆتایی {{ payment_method.expires }} بەسەردەچێت.

[کارتە نوێیەکەت زیاد بکە]({{ payment_methods_url }}) بۆ ئەوەی نوێکردنەوەکانت هەر خۆیان بدرێن.

{{ company.name }}
MD],
    'invoice.reminder' => ['بیرخستنەوە: کاتی پسوولەی {{ invoice.number }} تێپەڕیوە', <<<'MD'
سڵاو {{ client.first_name }}،

پسوولەی **{{ invoice.number }}** بە بڕی **{{ invoice.balance }}** کاتی دانی {{ invoice.due_date }} بوو و هێشتا نەدراوە.

[ئێستا بیدە]({{ invoice.url }})

ئەو خزمەتگوزارییانەی پسوولەی نەدراویان هەیە دوای چەند ڕۆژێک ڕادەگیرێن. ئەگەر پێشتر پارەکەت داوە، گوێ بەم ئیمەیڵە مەدە.

{{ company.name }}
MD],
    'service.welcome' => ['{{ service.product }} ـەکەت ئامادەیە', <<<'MD'
سڵاو {{ client.first_name }}،

**{{ service.product }}** ـەکەت بۆ **{{ service.domain }}** ئامادە کراوە و دەتوانیت بەکاری بهێنیت.

- ناوی بەکارهێنەر: {{ service.username }}
- سێرڤەر: {{ service.server }}

وشەی نهێنییەکەت و چوونەژوورەوە بە یەک کرتە بۆ کۆنتڕۆڵ پانێڵ لە ناوچەی کڕیاراندان:

[وردەکارییەکانی خزمەتگوزاری بکەرەوە]({{ service.url }})

{{ company.name }}
MD],
    'service.suspended' => ['خزمەتگوزاری ڕاگیرا: {{ service.domain }}', <<<'MD'
سڵاو {{ client.first_name }}،

**{{ service.product }}** ـەکەت بۆ **{{ service.domain }}** ڕاگیرا.

هۆکار: {{ reason }}

هەر پسوولەیەکی کراوە بدە بۆ ئەوەی خۆکار دیسان کار بکاتەوە، یان ئەگەر یارمەتیت پێویستە وەڵامی ئەم ئیمەیڵە بدەرەوە.

[بچۆ بۆ هەژمارەکەت]({{ client_area_url }})

{{ company.name }}
MD],
    'service.plan_changed' => ['پلانەکەت ئێستا {{ plan.new }} ـە', <<<'MD'
سڵاو {{ client.first_name }}،

خزمەتگوزارییەکەت **{{ service.domain }}** لە **{{ plan.old }}** ەوە چووە سەر **{{ plan.new }}**.

لە نوێکردنەوەی داهاتووەوە {{ plan.amount }} ({{ plan.cycle }}) دەدەیت. {{ plan.note }}

[خزمەتگوزارییەکەت ببینە]({{ service.url }})

{{ company.name }}
MD],
    'service.unsuspended' => ['خزمەتگوزاری دیسان چالاکە: {{ service.domain }}', <<<'MD'
سڵاو {{ client.first_name }}،

هەواڵی خۆش: **{{ service.product }}** ـەکەت بۆ **{{ service.domain }}** دیسان چالاکە.

{{ company.name }}
MD],
    'domain.registered' => ['دۆمەینەکەت {{ domain.name }} تۆمار کرا', <<<'MD'
سڵاو {{ client.first_name }}،

هەواڵی خۆش: **{{ domain.name }}** ئێستا تا **{{ domain.expires_at }}** بە ناوی تۆوە تۆمار کراوە.

نەیمسێرڤەرەکان: {{ domain.nameservers }}

لەوانەیە چەند کاتژمێرێک بخایەنێت تا دۆمەینەکە لە هەموو شوێنێکی ئینتەرنێت کار بکات.

[دۆمەینەکەت بەڕێوەببە]({{ domain.url }})

{{ company.name }}
MD],
    'domain.transfer_started' => ['گواستنەوەی {{ domain.name }} دەستی پێکرد', <<<'MD'
سڵاو {{ client.first_name }}،

گواستنەوەی **{{ domain.name }}** مان بۆ لای خۆمان دەست پێکرد. گواستنەوە زۆرجار 5 تا 7 ڕۆژ دەخایەنێت.

لەوانەیە تۆمارکەری ئێستات ئیمەیڵێکت بۆ بنێرێت بۆ ڕەزامەندی لەسەر گواستنەوەکە. ڕەزامەندی گواستنەوەکە خێراتر دەکات.

[گواستنەوەکە بەدوادا بچۆ]({{ domain.url }})

{{ company.name }}
MD],
    'domain.renewed' => ['دۆمەینەکەت {{ domain.name }} نوێ کرایەوە', <<<'MD'
سڵاو {{ client.first_name }}،

سوپاس! **{{ domain.name }}** نوێ کرایەوە. ئێستا لە **{{ domain.expires_at }}** بەسەردەچێت.

[دۆمەینەکەت بەڕێوەببە]({{ domain.url }})

{{ company.name }}
MD],
    'domain.expiring' => ['{{ domain.name }} لە ماوەی {{ days_left }} ڕۆژدا بەسەردەچێت', <<<'MD'
سڵاو {{ client.first_name }}،

دۆمەینەکەت **{{ domain.name }}** لە **{{ domain.expires_at }}** بەسەردەچێت و خۆی نوێ نابێتەوە.

ئەگەر دەتەوێت بیهێڵیتەوە، ئێستا نوێی بکەرەوە. دۆمەینی بەسەرچوو کار ناکات و کەسێکی تر دەتوانێت تۆماری بکات.

[{{ domain.name }} نوێ بکەرەوە]({{ domain.url }})

{{ company.name }}
MD],
    'ticket.opened' => ['[تیکت #{{ ticket.number }}] {{ ticket.subject }}', <<<'MD'
سڵاو {{ client.first_name }}،

تیکتەکەتمان وەرگرت و بە زووترین کات وەڵامت دەدەینەوە.

**{{ ticket.subject }}** · {{ ticket.department }}

[تیکتەکەت ببینە]({{ ticket.url }})

{{ company.name }}
MD],
    'ticket.reply' => ['[تیکت #{{ ticket.number }}] وەڵامی نوێ: {{ ticket.subject }}', <<<'MD'
سڵاو {{ client.first_name }}،

{{ reply.author }} وەڵامی تیکتەکەتی دایەوە:

{{ reply.message }}

[تیکتەکە ببینە و وەڵام بدەرەوە]({{ ticket.url }})

{{ company.name }}
MD],
    'admin.security_alert' => ['کێشەیەکی ئاسایشی نوێ لە {{ company.name }}', <<<'MD'
سڵاو {{ staff.name }}،

پشکنینی تەندروستی ماڵپەڕی ئەمشەو شتێکی نوێی دۆزیەوە کە پێویستی بە تۆیە:

{{ issues }}

نمرەی ئاسایشەکەت **{{ score }} لە 100** ـە.

[تەندروستی ماڵپەڕ بکەرەوە]({{ admin_url }})

ئەم ئیمەیڵەت پێدەگات چونکە دەتوانیت کێشە ئاسایشییەکان ببینیت و چاکیان بکەیت. وشەی نهێنی و ناوەڕۆکی فایلەکان هەرگیز ناخرێنە ناو ئەم ئیمەیڵانە.
MD],
    'admin.new_order' => ['داواکاری نوێ #{{ order.number }} لە {{ client.name }}', <<<'MD'
{{ client.name }} ({{ client.email }}) داواکاری **#{{ order.number }}** ی بە بڕی **{{ order.total }}** کرد.

[داواکارییەکە بکەرەوە]({{ admin_url }})
MD],
    'admin.ticket_opened' => ['تیکتی نوێ #{{ ticket.number }}: {{ ticket.subject }}', <<<'MD'
{{ client.name }} تیکتێکی لە {{ ticket.department }} کردەوە:

**{{ ticket.subject }}**

{{ reply.message }}

[ئێستا وەڵام بدەرەوە]({{ admin_url }})
MD],
    'admin.ticket_reply' => ['کڕیار وەڵامی تیکتی #{{ ticket.number }} ی دایەوە', <<<'MD'
{{ client.name }} وەڵامی **{{ ticket.subject }}** ی دایەوە:

{{ reply.message }}

[ئێستا وەڵام بدەرەوە]({{ admin_url }})
MD],
    'quote.sent' => ['نرخنامەی {{ quote.number }} لە {{ company.name }}', <<<'MD'
سڵاو {{ client.first_name }}،

ئەمە نرخنامەکەتە **{{ quote.number }}**: {{ quote.subject }}

کۆی گشتی: **{{ quote.total }}**، تا {{ quote.valid_until }} کار دەکات.

[نرخنامەکە ببینە و قبووڵی بکە]({{ quote.url }})

پرسیارت هەیە؟ تەنها وەڵامی ئەم ئیمەیڵە بدەرەوە.

{{ company.name }}
MD],
    'admin.quote_accepted' => ['{{ client.name }} نرخنامەی {{ quote.number }} ی قبووڵ کرد', <<<'MD'
{{ client.name }} نرخنامەی **{{ quote.number }}** ({{ quote.subject }}) ی بە بڕی **{{ quote.total }}** قبووڵ کرد.

پسوولەی {{ invoice.number }} بۆی دروست کرا.

[نرخنامەکە بکەرەوە]({{ admin_url }})
MD],
    'affiliate.commission' => ['{{ commission.amount }} ت لە ناساندنێک قازانج کرد', <<<'MD'
سڵاو {{ client.first_name }}،

کەسێک کە بە {{ company.name }} ت ناساندبوو پسوولەیەکی دا، و **{{ commission.amount }}** ت قازانج کرد.

بڕەکە لە {{ commission.available_on }} بەردەست دەبێت. ئەوکات دەتوانیت بیگوازیتەوە بۆ جزدانەکەت.

[هەژماری هاوبەشییەکەت ببینە]({{ affiliate_url }})

{{ company.name }}
MD],
    'marketplace.license' => ['کلیلی مۆڵەتی {{ item.name }}', <<<'MD'
سڵاو {{ client.first_name }}،

سوپاس بۆ کڕینی **{{ item.name }}**. ئەمە کلیلی مۆڵەتەکەتە:

**{{ license.key }}**

بۆ دامەزراندنی، ناوچەی بەڕێوەبردنی Nuvabill بکەرەوە، بچۆ بۆ **بازاڕ**، {{ item.name }} بدۆزەرەوە، کلیلەکە دابنێ و کرتە لە **دامەزراندن** بکە.

کلیلەکە بە یەکەم ماڵپەڕ دەبەسترێتەوە کە لەسەری دایدەمەزرێنیت. دەتوانیت لە هەژمارەکەتەوە بیگوازیتەوە بۆ ماڵپەڕێکی تر.

[هەژمارەکەت بکەرەوە]({{ service.url }})

{{ company.name }}
MD],
    'marketplace.review' => ['{{ item.name }} {{ item.version }}: {{ review.outcome }}', <<<'MD'
سڵاو {{ client.first_name }}،

**{{ item.name }} {{ item.version }}** مان پشکنی. ئەنجام: {{ review.outcome }}.

{{ review.message }}

[هەژماری گەشەپێدەرەکەت بکەرەوە]({{ developer_url }})

{{ company.name }}
MD],
];
