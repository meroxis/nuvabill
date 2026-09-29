<?php

// Arabic versions of the built-in email templates. Placeholders stay exactly as in English.
return [
    'client.welcome' => ['أهلًا بك في {{ company.name }}', <<<'MD'
مرحبًا {{ client.first_name }}،

شكرًا لإنشاء حساب لدى {{ company.name }}.

سجّل الدخول في أي وقت لطلب الخدمات ودفع الفواتير والتواصل مع الدعم:

[الذهاب إلى حسابك]({{ client_area_url }})

إذا لم تختر كلمة مرور بعد، استخدم «نسيت كلمة المرور» في صفحة تسجيل الدخول.

شكرًا،
{{ company.name }}
MD],
    'client.two_factor_code' => ['رمز الدخول إلى {{ company.name }}', <<<'MD'
مرحبًا {{ client.first_name }}،

رمز الدخول الخاص بك هو: **{{ code }}**

يعمل لمدة 10 دقائق. لا تشارك هذا الرمز أبدًا: فريقنا لن يطلبه منك أبدًا.

إذا لم تحاول تسجيل الدخول، غيّر كلمة المرور الآن.

{{ company.name }}
MD],
    'order.confirmation' => ['تم استلام الطلب رقم {{ order.number }}', <<<'MD'
مرحبًا {{ client.first_name }}،

شكرًا على طلبك **رقم {{ order.number }}**. المجموع **{{ order.total }}**.

[دفع الفاتورة {{ invoice.number }}]({{ invoice.url }})

نجهّز خدمتك فور وصول الدفعة ونرسل لك التفاصيل بالبريد.

شكرًا،
{{ company.name }}
MD],
    'invoice.created' => ['الفاتورة {{ invoice.number }} جاهزة', <<<'MD'
مرحبًا {{ client.first_name }}،

الفاتورة **{{ invoice.number }}** بمبلغ **{{ invoice.total }}** جاهزة. تاريخ استحقاقها **{{ invoice.due_date }}**.

[عرض الفاتورة ودفعها]({{ invoice.url }})

شكرًا،
{{ company.name }}
MD],
    'invoice.credit_note' => ['إشعار دائن {{ credit_note.number }} للفاتورة {{ invoice.number }}', <<<'MD'
مرحبًا {{ client.first_name }}،

أصدرنا الإشعار الدائن **{{ credit_note.number }}** بمبلغ **{{ credit_note.total }}** على الفاتورة **{{ invoice.number }}**.

{{ credit_note.note }}

[عرض الفاتورة والإشعار الدائن]({{ invoice.url }})

{{ company.name }}
MD],
    'invoice.payment_received' => ['تم استلام الدفعة للفاتورة {{ invoice.number }}', <<<'MD'
مرحبًا {{ client.first_name }}،

استلمنا دفعتك للفاتورة **{{ invoice.number }}**. شكرًا لك!

[عرض الفاتورة]({{ invoice.url }})

{{ company.name }}
MD],
    'invoice.autopay_upcoming' => ['ستُدفع الفاتورة {{ invoice.number }} تلقائيًا في {{ charge_date }}', <<<'MD'
مرحبًا {{ client.first_name }}،

في **{{ charge_date }}** سنخصم **{{ invoice.balance }}** للفاتورة **{{ invoice.number }}** من {{ payment_method.name }}. لا تحتاج إلى فعل أي شيء.

[عرض الفاتورة]({{ invoice.url }})

لاستخدام بطاقة أخرى أو إيقاف الدفع التلقائي، افتح [طرق الدفع]({{ payment_methods_url }}).

{{ company.name }}
MD],
    'invoice.autopay_failed' => ['تعذّر الخصم من {{ payment_method.name }} للفاتورة {{ invoice.number }}', <<<'MD'
مرحبًا {{ client.first_name }}،

حاولنا اليوم خصم **{{ invoice.balance }}** للفاتورة **{{ invoice.number }}** من {{ payment_method.name }}، لكن العملية لم تنجح: {{ failure }} لم يُخصم أي مبلغ من حسابك.

{{ next_try }}

[دفع الفاتورة]({{ invoice.url }})

إذا انتهت صلاحية بطاقتك أو صار لها رقم جديد، [أضف البطاقة الجديدة]({{ payment_methods_url }}) وسنستخدمها في المحاولة التالية.

{{ company.name }}
MD],
    'payment.method_expiring' => ['بطاقتك المحفوظة تنتهي صلاحيتها قريبًا', <<<'MD'
مرحبًا {{ client.first_name }}،

تنتهي صلاحية {{ payment_method.name }} التي تدفع تجديداتك تلقائيًا في نهاية {{ payment_method.expires }}.

[أضف بطاقتك الجديدة]({{ payment_methods_url }}) لتستمر تجديداتك في الدفع تلقائيًا.

{{ company.name }}
MD],
    'invoice.reminder' => ['تذكير: الفاتورة {{ invoice.number }} متأخرة', <<<'MD'
مرحبًا {{ client.first_name }}،

الفاتورة **{{ invoice.number }}** بمبلغ **{{ invoice.balance }}** كان موعد استحقاقها {{ invoice.due_date }} ولم تُدفع بعد.

[ادفع الآن]({{ invoice.url }})

تُعلَّق الخدمات التي لها فواتير غير مدفوعة بعد بضعة أيام. إذا كنت قد دفعت بالفعل، تجاهل هذه الرسالة.

{{ company.name }}
MD],
    'service.welcome' => ['خدمة {{ service.product }} جاهزة', <<<'MD'
مرحبًا {{ client.first_name }}،

خدمة **{{ service.product }}** لـ **{{ service.domain }}** جاهزة للاستخدام.

- اسم المستخدم: {{ service.username }}
- الخادم: {{ service.server }}

كلمة المرور والدخول إلى لوحة التحكم بنقرة واحدة موجودان في منطقة العملاء:

[فتح تفاصيل الخدمة]({{ service.url }})

{{ company.name }}
MD],
    'service.suspended' => ['تم تعليق الخدمة: {{ service.domain }}', <<<'MD'
مرحبًا {{ client.first_name }}،

تم تعليق خدمة **{{ service.product }}** لـ **{{ service.domain }}**.

السبب: {{ reason }}

ادفع أي فاتورة مفتوحة لتعود الخدمة للعمل تلقائيًا، أو رُدّ على هذه الرسالة إذا احتجت إلى مساعدة.

[الذهاب إلى حسابك]({{ client_area_url }})

{{ company.name }}
MD],
    'service.plan_changed' => ['خطتك الآن {{ plan.new }}', <<<'MD'
مرحبًا {{ client.first_name }}،

انتقلت خدمتك **{{ service.domain }}** من **{{ plan.old }}** إلى **{{ plan.new }}**.

من التجديد القادم تدفع {{ plan.amount }} ({{ plan.cycle }}). {{ plan.note }}

[عرض خدمتك]({{ service.url }})

{{ company.name }}
MD],
    'service.unsuspended' => ['الخدمة نشطة من جديد: {{ service.domain }}', <<<'MD'
مرحبًا {{ client.first_name }}،

خبر جيد: خدمة **{{ service.product }}** لـ **{{ service.domain }}** نشطة من جديد.

{{ company.name }}
MD],
    'domain.registered' => ['تم تسجيل نطاقك {{ domain.name }}', <<<'MD'
مرحبًا {{ client.first_name }}،

خبر جيد: **{{ domain.name }}** مسجّل الآن باسمك حتى **{{ domain.expires_at }}**.

خوادم الأسماء: {{ domain.nameservers }}

قد يستغرق عمل النطاق في كل مكان على الإنترنت بضع ساعات.

[إدارة نطاقك]({{ domain.url }})

{{ company.name }}
MD],
    'domain.transfer_started' => ['بدأ نقل {{ domain.name }}', <<<'MD'
مرحبًا {{ client.first_name }}،

بدأنا نقل **{{ domain.name }}** إلينا. يستغرق النقل عادةً من 5 إلى 7 أيام.

قد يرسل لك المسجّل الحالي رسالة لتوافق على النقل. الموافقة تجعل النقل أسرع.

[متابعة النقل]({{ domain.url }})

{{ company.name }}
MD],
    'domain.renewed' => ['تم تجديد نطاقك {{ domain.name }}', <<<'MD'
مرحبًا {{ client.first_name }}،

شكرًا لك! تم تجديد **{{ domain.name }}**. ينتهي الآن في **{{ domain.expires_at }}**.

[إدارة نطاقك]({{ domain.url }})

{{ company.name }}
MD],
    'domain.expiring' => ['ينتهي {{ domain.name }} خلال {{ days_left }} يومًا', <<<'MD'
مرحبًا {{ client.first_name }}،

ينتهي نطاقك **{{ domain.name }}** في **{{ domain.expires_at }}** ولن يتجدد تلقائيًا.

إذا أردت الاحتفاظ به، جدّده الآن. النطاق المنتهي يتوقف عن العمل وقد يسجّله شخص آخر.

[تجديد {{ domain.name }}]({{ domain.url }})

{{ company.name }}
MD],
    'ticket.opened' => ['[التذكرة #{{ ticket.number }}] {{ ticket.subject }}', <<<'MD'
مرحبًا {{ client.first_name }}،

استلمنا تذكرتك وسنرد عليك في أقرب وقت ممكن.

**{{ ticket.subject }}** · {{ ticket.department }}

[عرض تذكرتك]({{ ticket.url }})

{{ company.name }}
MD],
    'ticket.reply' => ['[التذكرة #{{ ticket.number }}] رد جديد: {{ ticket.subject }}', <<<'MD'
مرحبًا {{ client.first_name }}،

ردّ {{ reply.author }} على تذكرتك:

{{ reply.message }}

[عرض التذكرة والرد]({{ ticket.url }})

{{ company.name }}
MD],
    'admin.security_alert' => ['مشكلة أمنية جديدة في {{ company.name }}', <<<'MD'
مرحبًا {{ staff.name }}،

وجد فحص صحة الموقع الليلة شيئًا جديدًا يحتاج إليك:

{{ issues }}

درجة الأمان لديك **{{ score }} من 100**.

[فتح صحة الموقع]({{ admin_url }})

تصلك هذه الرسالة لأنك تستطيع رؤية المشكلات الأمنية وإصلاحها. لا توضع كلمات المرور ولا محتويات الملفات في هذه الرسائل أبدًا.
MD],
    'admin.new_order' => ['طلب جديد #{{ order.number }} من {{ client.name }}', <<<'MD'
قدّم {{ client.name }} ({{ client.email }}) الطلب **#{{ order.number }}** بمبلغ **{{ order.total }}**.

[فتح الطلب]({{ admin_url }})
MD],
    'admin.ticket_opened' => ['تذكرة جديدة #{{ ticket.number }}: {{ ticket.subject }}', <<<'MD'
فتح {{ client.name }} تذكرة في {{ ticket.department }}:

**{{ ticket.subject }}**

{{ reply.message }}

[الرد الآن]({{ admin_url }})
MD],
    'admin.ticket_reply' => ['ردّ العميل على التذكرة #{{ ticket.number }}', <<<'MD'
ردّ {{ client.name }} على **{{ ticket.subject }}**:

{{ reply.message }}

[الرد الآن]({{ admin_url }})
MD],
    'quote.sent' => ['عرض السعر {{ quote.number }} من {{ company.name }}', <<<'MD'
مرحبًا {{ client.first_name }}،

إليك عرض السعر **{{ quote.number }}**: {{ quote.subject }}

المجموع: **{{ quote.total }}**، صالح حتى {{ quote.valid_until }}.

[عرض عرض السعر وقبوله]({{ quote.url }})

لديك أسئلة؟ رُدّ على هذه الرسالة فقط.

{{ company.name }}
MD],
    'admin.quote_accepted' => ['قبل {{ client.name }} عرض السعر {{ quote.number }}', <<<'MD'
قبل {{ client.name }} عرض السعر **{{ quote.number }}** ({{ quote.subject }}) بمبلغ **{{ quote.total }}**.

أُنشئت له الفاتورة {{ invoice.number }}.

[فتح عرض السعر]({{ admin_url }})
MD],
    'affiliate.commission' => ['ربحت {{ commission.amount }} من إحالة', <<<'MD'
مرحبًا {{ client.first_name }}،

شخص أحلته إلى {{ company.name }} دفع فاتورة، وربحت **{{ commission.amount }}**.

يصبح المبلغ متاحًا في {{ commission.available_on }}. بعدها يمكنك نقله إلى محفظتك.

[عرض حساب الشراكة]({{ affiliate_url }})

{{ company.name }}
MD],
    'marketplace.license' => ['مفتاح ترخيص {{ item.name }}', <<<'MD'
مرحبًا {{ client.first_name }}،

شكرًا لشراء **{{ item.name }}**. هذا مفتاح الترخيص الخاص بك:

**{{ license.key }}**

لتثبيته، افتح لوحة إدارة Nuvabill، واذهب إلى **سوق الإضافات**، وابحث عن {{ item.name }}، والصق المفتاح، ثم انقر **تثبيت**.

يرتبط المفتاح بأول موقع تثبّته عليه. يمكنك نقله إلى موقع آخر من حسابك.

[فتح حسابك]({{ service.url }})

{{ company.name }}
MD],
    'marketplace.review' => ['{{ item.name }} {{ item.version }}: {{ review.outcome }}', <<<'MD'
مرحبًا {{ client.first_name }}،

راجعنا **{{ item.name }} {{ item.version }}**. النتيجة: {{ review.outcome }}.

{{ review.message }}

[فتح حساب المطوّر]({{ developer_url }})

{{ company.name }}
MD],
];
