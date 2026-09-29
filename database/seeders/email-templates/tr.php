<?php

// Turkish versions of the built-in email templates. Placeholders stay exactly as in English.
return [
    'client.welcome' => ['{{ company.name }} ailesine hoş geldiniz', <<<'MD'
Merhaba {{ client.first_name }},

{{ company.name }} üzerinde hesap oluşturduğunuz için teşekkür ederiz.

Hizmet sipariş etmek, fatura ödemek ve destekle iletişime geçmek için istediğiniz zaman giriş yapabilirsiniz:

[Hesabınıza gidin]({{ client_area_url }})

Henüz bir şifre seçmediyseniz, giriş sayfasındaki “Şifremi unuttum” bağlantısını kullanın.

Teşekkürler,
{{ company.name }}
MD],
    'client.two_factor_code' => ['{{ company.name }} giriş kodunuz', <<<'MD'
Merhaba {{ client.first_name }},

Giriş kodunuz: **{{ code }}**

Kod 10 dakika geçerlidir. Bu kodu asla paylaşmayın: ekibimiz bunu hiçbir zaman sormaz.

Giriş yapmayı siz denemediyseniz, şifrenizi hemen değiştirin.

{{ company.name }}
MD],
    'order.confirmation' => ['#{{ order.number }} numaralı sipariş alındı', <<<'MD'
Merhaba {{ client.first_name }},

**#{{ order.number }}** numaralı siparişiniz için teşekkürler. Toplam tutar **{{ order.total }}**.

[{{ invoice.number }} faturasını ödeyin]({{ invoice.url }})

Ödeme ulaşır ulaşmaz hizmetinizi kurar ve bilgileri size e-postayla göndeririz.

Teşekkürler,
{{ company.name }}
MD],
    'invoice.created' => ['{{ invoice.number }} faturası hazır', <<<'MD'
Merhaba {{ client.first_name }},

**{{ invoice.total }}** tutarındaki **{{ invoice.number }}** faturası hazır. Son ödeme tarihi **{{ invoice.due_date }}**.

[Faturayı görüntüleyin ve ödeyin]({{ invoice.url }})

Teşekkürler,
{{ company.name }}
MD],
    'invoice.credit_note' => ['{{ invoice.number }} faturası için {{ credit_note.number }} alacak dekontu', <<<'MD'
Merhaba {{ client.first_name }},

**{{ invoice.number }}** faturası için **{{ credit_note.total }}** tutarında **{{ credit_note.number }}** alacak dekontu düzenledik.

{{ credit_note.note }}

[Faturayı ve alacak dekontunu görüntüleyin]({{ invoice.url }})

{{ company.name }}
MD],
    'invoice.payment_received' => ['{{ invoice.number }} faturası için ödeme alındı', <<<'MD'
Merhaba {{ client.first_name }},

**{{ invoice.number }}** faturası için ödemenizi aldık. Teşekkürler!

[Faturayı görüntüleyin]({{ invoice.url }})

{{ company.name }}
MD],
    'invoice.autopay_upcoming' => ['{{ invoice.number }} faturası {{ charge_date }} tarihinde otomatik ödenecek', <<<'MD'
Merhaba {{ client.first_name }},

**{{ charge_date }}** tarihinde **{{ invoice.number }}** faturası için **{{ invoice.balance }}** tutarını {{ payment_method.name }} üzerinden tahsil edeceğiz. Bir şey yapmanıza gerek yok.

[Faturayı görüntüleyin]({{ invoice.url }})

Başka bir kart kullanmak veya otomatik ödemeleri kapatmak için [Ödeme yöntemleri]({{ payment_methods_url }}) sayfasını açın.

{{ company.name }}
MD],
    'invoice.autopay_failed' => ['{{ invoice.number }} faturası için {{ payment_method.name }} üzerinden tahsilat yapılamadı', <<<'MD'
Merhaba {{ client.first_name }},

Bugün **{{ invoice.number }}** faturası için **{{ invoice.balance }}** tutarını {{ payment_method.name }} üzerinden tahsil etmeyi denedik ama olmadı: {{ failure }} Hesabınızdan hiçbir şey çekilmedi.

{{ next_try }}

[Faturayı ödeyin]({{ invoice.url }})

Kartınızın süresi dolduysa veya yeni bir numarası varsa, [yeni kartı ekleyin]({{ payment_methods_url }}), bir sonraki denemede onu kullanalım.

{{ company.name }}
MD],
    'payment.method_expiring' => ['Kayıtlı kartınızın süresi yakında doluyor', <<<'MD'
Merhaba {{ client.first_name }},

Yenilemelerinizi otomatik ödeyen {{ payment_method.name }} kartınızın süresi {{ payment_method.expires }} sonunda doluyor.

Yenilemelerinizin kendiliğinden ödenmeye devam etmesi için [yeni kartınızı ekleyin]({{ payment_methods_url }}).

{{ company.name }}
MD],
    'invoice.reminder' => ['Hatırlatma: {{ invoice.number }} faturasının süresi geçti', <<<'MD'
Merhaba {{ client.first_name }},

**{{ invoice.balance }}** tutarındaki **{{ invoice.number }}** faturasının son ödeme tarihi {{ invoice.due_date }} idi ve hâlâ ödenmedi.

[Şimdi ödeyin]({{ invoice.url }})

Ödenmemiş faturası olan hizmetler birkaç gün sonra askıya alınır. Zaten ödediyseniz bu e-postayı dikkate almayın.

{{ company.name }}
MD],
    'service.welcome' => ['{{ service.product }} hizmetiniz hazır', <<<'MD'
Merhaba {{ client.first_name }},

**{{ service.domain }}** için **{{ service.product }}** hizmetiniz kuruldu ve kullanıma hazır.

- Kullanıcı adı: {{ service.username }}
- Sunucu: {{ service.server }}

Şifreniz ve tek tıkla kontrol paneli girişi müşteri panelinizde:

[Hizmet ayrıntılarını açın]({{ service.url }})

{{ company.name }}
MD],
    'service.suspended' => ['Hizmet askıya alındı: {{ service.domain }}', <<<'MD'
Merhaba {{ client.first_name }},

**{{ service.domain }}** için **{{ service.product }}** hizmetiniz askıya alındı.

Neden: {{ reason }}

Hizmetin kendiliğinden yeniden açılması için açık faturaları ödeyin veya yardıma ihtiyacınız varsa bu e-postayı yanıtlayın.

[Hesabınıza gidin]({{ client_area_url }})

{{ company.name }}
MD],
    'service.plan_changed' => ['Paketiniz artık {{ plan.new }}', <<<'MD'
Merhaba {{ client.first_name }},

**{{ service.domain }}** hizmetiniz **{{ plan.old }}** paketinden **{{ plan.new }}** paketine geçti.

Bir sonraki yenilemeden itibaren {{ plan.amount }} ({{ plan.cycle }}) ödersiniz. {{ plan.note }}

[Hizmetinizi görün]({{ service.url }})

{{ company.name }}
MD],
    'service.unsuspended' => ['Hizmet yeniden etkin: {{ service.domain }}', <<<'MD'
Merhaba {{ client.first_name }},

Güzel haber: **{{ service.domain }}** için **{{ service.product }}** hizmetiniz yeniden etkin.

{{ company.name }}
MD],
    'domain.registered' => ['{{ domain.name }} alan adınız kaydedildi', <<<'MD'
Merhaba {{ client.first_name }},

Güzel haber: **{{ domain.name }}** artık **{{ domain.expires_at }}** tarihine kadar adınıza kayıtlı.

Ad sunucuları: {{ domain.nameservers }}

Alan adının internetin her yerinde çalışması birkaç saat sürebilir.

[Alan adınızı yönetin]({{ domain.url }})

{{ company.name }}
MD],
    'domain.transfer_started' => ['{{ domain.name }} transferi başladı', <<<'MD'
Merhaba {{ client.first_name }},

**{{ domain.name }}** alan adının bize transferini başlattık. Transferler genellikle 5 ila 7 gün sürer.

Mevcut kayıt kuruluşunuz, transferi onaylamanız için size e-posta gönderebilir. Onaylamak transferi hızlandırır.

[Transferi takip edin]({{ domain.url }})

{{ company.name }}
MD],
    'domain.renewed' => ['{{ domain.name }} alan adınız yenilendi', <<<'MD'
Merhaba {{ client.first_name }},

Teşekkürler! **{{ domain.name }}** yenilendi. Artık **{{ domain.expires_at }}** tarihinde sona eriyor.

[Alan adınızı yönetin]({{ domain.url }})

{{ company.name }}
MD],
    'domain.expiring' => ['{{ domain.name }} {{ days_left }} gün içinde sona eriyor', <<<'MD'
Merhaba {{ client.first_name }},

**{{ domain.name }}** alan adınız **{{ domain.expires_at }}** tarihinde sona eriyor ve kendiliğinden yenilenmeyecek.

Saklamak istiyorsanız şimdi yenileyin. Süresi dolan bir alan adı çalışmaz ve başkası tarafından kaydedilebilir.

[{{ domain.name }} alan adını yenileyin]({{ domain.url }})

{{ company.name }}
MD],
    'ticket.opened' => ['[Talep #{{ ticket.number }}] {{ ticket.subject }}', <<<'MD'
Merhaba {{ client.first_name }},

Talebinizi aldık ve en kısa sürede yanıtlayacağız.

**{{ ticket.subject }}** · {{ ticket.department }}

[Talebinizi görüntüleyin]({{ ticket.url }})

{{ company.name }}
MD],
    'ticket.reply' => ['[Talep #{{ ticket.number }}] Yeni yanıt: {{ ticket.subject }}', <<<'MD'
Merhaba {{ client.first_name }},

{{ reply.author }} talebinizi yanıtladı:

{{ reply.message }}

[Talebi görüntüleyin ve yanıtlayın]({{ ticket.url }})

{{ company.name }}
MD],
    'admin.security_alert' => ['{{ company.name }} üzerinde yeni güvenlik sorunu', <<<'MD'
Merhaba {{ staff.name }},

Bu geceki site sağlığı kontrolü ilgilenmeniz gereken yeni bir şey buldu:

{{ issues }}

Güvenlik puanınız **{{ score }} / 100**.

[Site sağlığını açın]({{ admin_url }})

Bu e-postayı, güvenlik sorunlarını görüp düzeltebildiğiniz için alıyorsunuz. Şifreler ve dosya içerikleri bu e-postalara asla eklenmez.
MD],
    'admin.new_order' => ['{{ client.name }} kişisinden yeni sipariş #{{ order.number }}', <<<'MD'
{{ client.name }} ({{ client.email }}), **{{ order.total }}** tutarında **#{{ order.number }}** numaralı sipariş verdi.

[Siparişi açın]({{ admin_url }})
MD],
    'admin.ticket_opened' => ['Yeni talep #{{ ticket.number }}: {{ ticket.subject }}', <<<'MD'
{{ client.name }}, {{ ticket.department }} departmanında bir talep açtı:

**{{ ticket.subject }}**

{{ reply.message }}

[Şimdi yanıtlayın]({{ admin_url }})
MD],
    'admin.ticket_reply' => ['Müşteri #{{ ticket.number }} numaralı talebi yanıtladı', <<<'MD'
{{ client.name }}, **{{ ticket.subject }}** talebini yanıtladı:

{{ reply.message }}

[Şimdi yanıtlayın]({{ admin_url }})
MD],
    'quote.sent' => ['{{ company.name }} teklifiniz {{ quote.number }}', <<<'MD'
Merhaba {{ client.first_name }},

İşte **{{ quote.number }}** numaralı teklifiniz: {{ quote.subject }}

Toplam: **{{ quote.total }}**, {{ quote.valid_until }} tarihine kadar geçerli.

[Teklifi görüntüleyin ve kabul edin]({{ quote.url }})

Sorunuz mu var? Bu e-postayı yanıtlamanız yeterli.

{{ company.name }}
MD],
    'admin.quote_accepted' => ['{{ quote.number }} teklifi {{ client.name }} tarafından kabul edildi', <<<'MD'
{{ client.name }}, **{{ quote.total }}** tutarındaki **{{ quote.number }}** ({{ quote.subject }}) teklifini kabul etti.

Bunun için {{ invoice.number }} faturası oluşturuldu.

[Teklifi açın]({{ admin_url }})
MD],
    'affiliate.commission' => ['Bir tavsiyeden {{ commission.amount }} kazandınız', <<<'MD'
Merhaba {{ client.first_name }},

{{ company.name }} için tavsiye ettiğiniz biri bir fatura ödedi ve **{{ commission.amount }}** kazandınız.

Tutar {{ commission.available_on }} tarihinde kullanılabilir olacak. Sonra onu cüzdanınıza aktarabilirsiniz.

[Ortaklık hesabınızı görün]({{ affiliate_url }})

{{ company.name }}
MD],
    'marketplace.license' => ['{{ item.name }} lisans anahtarınız', <<<'MD'
Merhaba {{ client.first_name }},

**{{ item.name }}** satın aldığınız için teşekkürler. Lisans anahtarınız:

**{{ license.key }}**

Kurmak için Nuvabill yönetim alanını açın, **Market** bölümüne gidin, {{ item.name }} ürününü bulun, anahtarı yapıştırın ve **Kur** düğmesine tıklayın.

Anahtar, kurduğunuz ilk siteye bağlanır. Hesabınızdan başka bir siteye taşıyabilirsiniz.

[Hesabınızı açın]({{ service.url }})

{{ company.name }}
MD],
    'marketplace.review' => ['{{ item.name }} {{ item.version }}: {{ review.outcome }}', <<<'MD'
Merhaba {{ client.first_name }},

**{{ item.name }} {{ item.version }}** sürümünü inceledik. Sonuç: {{ review.outcome }}.

{{ review.message }}

[Geliştirici hesabınızı açın]({{ developer_url }})

{{ company.name }}
MD],
];
