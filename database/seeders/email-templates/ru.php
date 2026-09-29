<?php

// Russian versions of the built-in email templates. Placeholders stay exactly as in English.
return [
    'client.welcome' => ['Добро пожаловать в {{ company.name }}', <<<'MD'
Здравствуйте, {{ client.first_name }}!

Спасибо, что создали аккаунт в {{ company.name }}.

Входите в любое время, чтобы заказывать услуги, оплачивать счета и писать в поддержку:

[Перейти в ваш аккаунт]({{ client_area_url }})

Если вы ещё не выбрали пароль, нажмите «Забыли пароль» на странице входа.

Спасибо,
{{ company.name }}
MD],
    'client.two_factor_code' => ['Ваш код входа в {{ company.name }}', <<<'MD'
Здравствуйте, {{ client.first_name }}!

Ваш код входа: **{{ code }}**

Он действует 10 минут. Никому не сообщайте этот код: наши сотрудники никогда его не спрашивают.

Если вы не пытались войти, смените пароль прямо сейчас.

{{ company.name }}
MD],
    'order.confirmation' => ['Заказ № {{ order.number }} получен', <<<'MD'
Здравствуйте, {{ client.first_name }}!

Спасибо за заказ **№ {{ order.number }}**. Сумма: **{{ order.total }}**.

[Оплатить счёт {{ invoice.number }}]({{ invoice.url }})

Мы настроим услугу, как только поступит оплата, и пришлём вам данные на почту.

Спасибо,
{{ company.name }}
MD],
    'invoice.created' => ['Счёт {{ invoice.number }} готов', <<<'MD'
Здравствуйте, {{ client.first_name }}!

Счёт **{{ invoice.number }}** на **{{ invoice.total }}** готов. Срок оплаты — **{{ invoice.due_date }}**.

[Посмотреть и оплатить счёт]({{ invoice.url }})

Спасибо,
{{ company.name }}
MD],
    'invoice.credit_note' => ['Кредит-нота {{ credit_note.number }} по счёту {{ invoice.number }}', <<<'MD'
Здравствуйте, {{ client.first_name }}!

Мы выставили кредит-ноту **{{ credit_note.number }}** на сумму **{{ credit_note.total }}** по счёту **{{ invoice.number }}**.

{{ credit_note.note }}

[Посмотреть счёт и кредит-ноту]({{ invoice.url }})

{{ company.name }}
MD],
    'invoice.payment_received' => ['Оплата по счёту {{ invoice.number }} получена', <<<'MD'
Здравствуйте, {{ client.first_name }}!

Мы получили вашу оплату по счёту **{{ invoice.number }}**. Спасибо!

[Посмотреть счёт]({{ invoice.url }})

{{ company.name }}
MD],
    'invoice.autopay_upcoming' => ['Счёт {{ invoice.number }} будет оплачен автоматически {{ charge_date }}', <<<'MD'
Здравствуйте, {{ client.first_name }}!

**{{ charge_date }}** мы спишем **{{ invoice.balance }}** по счёту **{{ invoice.number }}** с вашей {{ payment_method.name }}. Вам ничего не нужно делать.

[Посмотреть счёт]({{ invoice.url }})

Чтобы использовать другую карту или отключить автоплатежи, откройте [Способы оплаты]({{ payment_methods_url }}).

{{ company.name }}
MD],
    'invoice.autopay_failed' => ['Не удалось списать оплату по счёту {{ invoice.number }} с вашей {{ payment_method.name }}', <<<'MD'
Здравствуйте, {{ client.first_name }}!

Сегодня мы пытались списать **{{ invoice.balance }}** по счёту **{{ invoice.number }}** с вашей {{ payment_method.name }}, но не получилось: {{ failure }} С вашего счёта ничего не списано.

{{ next_try }}

[Оплатить счёт]({{ invoice.url }})

Если срок действия карты истёк или у неё новый номер, [добавьте новую карту]({{ payment_methods_url }}), и мы используем её при следующей попытке.

{{ company.name }}
MD],
    'payment.method_expiring' => ['Срок действия вашей сохранённой карты скоро истекает', <<<'MD'
Здравствуйте, {{ client.first_name }}!

Срок действия вашей {{ payment_method.name }}, которая автоматически оплачивает продления, истекает в конце {{ payment_method.expires }}.

[Добавьте новую карту]({{ payment_methods_url }}), чтобы продления и дальше оплачивались сами.

{{ company.name }}
MD],
    'invoice.reminder' => ['Напоминание: счёт {{ invoice.number }} просрочен', <<<'MD'
Здравствуйте, {{ client.first_name }}!

Счёт **{{ invoice.number }}** на **{{ invoice.balance }}** нужно было оплатить до {{ invoice.due_date }}, но он всё ещё не оплачен.

[Оплатить сейчас]({{ invoice.url }})

Услуги с неоплаченными счетами приостанавливаются через несколько дней. Если вы уже оплатили, просто не обращайте внимания на это письмо.

{{ company.name }}
MD],
    'service.welcome' => ['Ваш {{ service.product }} готов', <<<'MD'
Здравствуйте, {{ client.first_name }}!

Ваш **{{ service.product }}** для **{{ service.domain }}** настроен и готов к работе.

- Логин: {{ service.username }}
- Сервер: {{ service.server }}

Пароль и вход в панель управления в один клик — в вашем личном кабинете:

[Открыть данные услуги]({{ service.url }})

{{ company.name }}
MD],
    'service.suspended' => ['Услуга приостановлена: {{ service.domain }}', <<<'MD'
Здравствуйте, {{ client.first_name }}!

Ваш **{{ service.product }}** для **{{ service.domain }}** приостановлен.

Причина: {{ reason }}

Оплатите открытые счета, чтобы услуга включилась автоматически, или ответьте на это письмо, если нужна помощь.

[Перейти в ваш аккаунт]({{ client_area_url }})

{{ company.name }}
MD],
    'service.plan_changed' => ['Ваш тариф теперь {{ plan.new }}', <<<'MD'
Здравствуйте, {{ client.first_name }}!

Ваша услуга **{{ service.domain }}** перешла с **{{ plan.old }}** на **{{ plan.new }}**.

Со следующего продления вы платите {{ plan.amount }} ({{ plan.cycle }}). {{ plan.note }}

[Посмотреть услугу]({{ service.url }})

{{ company.name }}
MD],
    'service.unsuspended' => ['Услуга снова активна: {{ service.domain }}', <<<'MD'
Здравствуйте, {{ client.first_name }}!

Хорошая новость: ваш **{{ service.product }}** для **{{ service.domain }}** снова активен.

{{ company.name }}
MD],
    'domain.registered' => ['Ваш домен {{ domain.name }} зарегистрирован', <<<'MD'
Здравствуйте, {{ client.first_name }}!

Хорошая новость: **{{ domain.name }}** зарегистрирован на вас до **{{ domain.expires_at }}**.

DNS-серверы: {{ domain.nameservers }}

Может пройти несколько часов, прежде чем домен заработает по всему интернету.

[Управлять доменом]({{ domain.url }})

{{ company.name }}
MD],
    'domain.transfer_started' => ['Перенос {{ domain.name }} начался', <<<'MD'
Здравствуйте, {{ client.first_name }}!

Мы начали перенос **{{ domain.name }}** к нам. Обычно перенос занимает от 5 до 7 дней.

Ваш текущий регистратор может прислать письмо с просьбой подтвердить перенос. Если подтвердить, всё пройдёт быстрее.

[Следить за переносом]({{ domain.url }})

{{ company.name }}
MD],
    'domain.renewed' => ['Ваш домен {{ domain.name }} продлён', <<<'MD'
Здравствуйте, {{ client.first_name }}!

Спасибо! **{{ domain.name }}** продлён. Теперь он действует до **{{ domain.expires_at }}**.

[Управлять доменом]({{ domain.url }})

{{ company.name }}
MD],
    'domain.expiring' => ['Срок {{ domain.name }} истекает через {{ days_left }} дн.', <<<'MD'
Здравствуйте, {{ client.first_name }}!

Срок вашего домена **{{ domain.name }}** истекает **{{ domain.expires_at }}**, и сам он не продлится.

Если хотите сохранить домен, продлите его сейчас. Домен с истёкшим сроком перестаёт работать, и его может зарегистрировать кто-то другой.

[Продлить {{ domain.name }}]({{ domain.url }})

{{ company.name }}
MD],
    'ticket.opened' => ['[Тикет № {{ ticket.number }}] {{ ticket.subject }}', <<<'MD'
Здравствуйте, {{ client.first_name }}!

Мы получили ваш тикет и ответим как можно скорее.

**{{ ticket.subject }}** · {{ ticket.department }}

[Посмотреть тикет]({{ ticket.url }})

{{ company.name }}
MD],
    'ticket.reply' => ['[Тикет № {{ ticket.number }}] Новый ответ: {{ ticket.subject }}', <<<'MD'
Здравствуйте, {{ client.first_name }}!

{{ reply.author }} ответил(а) на ваш тикет:

{{ reply.message }}

[Посмотреть тикет и ответить]({{ ticket.url }})

{{ company.name }}
MD],
    'admin.security_alert' => ['Новая проблема безопасности на {{ company.name }}', <<<'MD'
Здравствуйте, {{ staff.name }}!

Ночная проверка сайта нашла кое-что новое, что требует вашего внимания:

{{ issues }}

Ваша оценка безопасности: **{{ score }} из 100**.

[Открыть состояние сайта]({{ admin_url }})

Вы получили это письмо, потому что можете видеть и исправлять проблемы безопасности. Пароли и содержимое файлов никогда не попадают в эти письма.
MD],
    'admin.new_order' => ['Новый заказ № {{ order.number }} от {{ client.name }}', <<<'MD'
{{ client.name }} ({{ client.email }}) оформил(а) заказ **№ {{ order.number }}** на **{{ order.total }}**.

[Открыть заказ]({{ admin_url }})
MD],
    'admin.ticket_opened' => ['Новый тикет № {{ ticket.number }}: {{ ticket.subject }}', <<<'MD'
{{ client.name }} открыл(а) тикет в отделе {{ ticket.department }}:

**{{ ticket.subject }}**

{{ reply.message }}

[Ответить сейчас]({{ admin_url }})
MD],
    'admin.ticket_reply' => ['Клиент ответил в тикете № {{ ticket.number }}', <<<'MD'
{{ client.name }} ответил(а) в **{{ ticket.subject }}**:

{{ reply.message }}

[Ответить сейчас]({{ admin_url }})
MD],
    'quote.sent' => ['Ваше коммерческое предложение {{ quote.number }} от {{ company.name }}', <<<'MD'
Здравствуйте, {{ client.first_name }}!

Вот ваше коммерческое предложение **{{ quote.number }}**: {{ quote.subject }}

Итого: **{{ quote.total }}**, действует до {{ quote.valid_until }}.

[Посмотреть и принять предложение]({{ quote.url }})

Есть вопросы? Просто ответьте на это письмо.

{{ company.name }}
MD],
    'admin.quote_accepted' => ['{{ client.name }} принял(а) предложение {{ quote.number }}', <<<'MD'
{{ client.name }} принял(а) коммерческое предложение **{{ quote.number }}** ({{ quote.subject }}) на **{{ quote.total }}**.

Для него создан счёт {{ invoice.number }}.

[Открыть предложение]({{ admin_url }})
MD],
    'affiliate.commission' => ['Вы заработали {{ commission.amount }} за рекомендацию', <<<'MD'
Здравствуйте, {{ client.first_name }}!

Человек, которого вы порекомендовали {{ company.name }}, оплатил счёт, и вы заработали **{{ commission.amount }}**.

Сумма станет доступна {{ commission.available_on }}. Тогда её можно будет перевести на баланс.

[Открыть партнёрский аккаунт]({{ affiliate_url }})

{{ company.name }}
MD],
    'marketplace.license' => ['Ваш лицензионный ключ {{ item.name }}', <<<'MD'
Здравствуйте, {{ client.first_name }}!

Спасибо за покупку **{{ item.name }}**. Вот ваш лицензионный ключ:

**{{ license.key }}**

Чтобы установить, откройте админку Nuvabill, перейдите в **Маркетплейс**, найдите {{ item.name }}, вставьте ключ и нажмите **Установить**.

Ключ привязывается к первому сайту, на который вы его установите. Перенести его на другой сайт можно в вашем аккаунте.

[Открыть аккаунт]({{ service.url }})

{{ company.name }}
MD],
    'marketplace.review' => ['{{ item.name }} {{ item.version }}: {{ review.outcome }}', <<<'MD'
Здравствуйте, {{ client.first_name }}!

Мы проверили **{{ item.name }} {{ item.version }}**. Результат: {{ review.outcome }}.

{{ review.message }}

[Открыть аккаунт разработчика]({{ developer_url }})

{{ company.name }}
MD],
];
