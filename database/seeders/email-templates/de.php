<?php

// German versions of the built-in email templates. Placeholders stay exactly as in English.
return [
    'client.welcome' => ['Willkommen bei {{ company.name }}', <<<'MD'
Hallo {{ client.first_name }},

danke, dass Sie ein Konto bei {{ company.name }} erstellt haben.

Melden Sie sich jederzeit an, um Dienste zu bestellen, Rechnungen zu bezahlen und den Support zu kontaktieren:

[Zu Ihrem Konto]({{ client_area_url }})

Wenn Sie noch kein Passwort gewählt haben, nutzen Sie „Passwort vergessen“ auf der Anmeldeseite.

Vielen Dank,
{{ company.name }}
MD],
    'client.two_factor_code' => ['Ihr Anmeldecode für {{ company.name }}', <<<'MD'
Hallo {{ client.first_name }},

Ihr Anmeldecode lautet: **{{ code }}**

Er gilt 10 Minuten. Geben Sie diesen Code niemals weiter: Unsere Mitarbeiter fragen nie danach.

Wenn Sie sich nicht anmelden wollten, ändern Sie jetzt Ihr Passwort.

{{ company.name }}
MD],
    'order.confirmation' => ['Bestellung #{{ order.number }} erhalten', <<<'MD'
Hallo {{ client.first_name }},

danke für Ihre Bestellung **#{{ order.number }}**. Der Gesamtbetrag ist **{{ order.total }}**.

[Rechnung {{ invoice.number }} bezahlen]({{ invoice.url }})

Wir richten Ihren Dienst ein, sobald die Zahlung eingeht, und schicken Ihnen die Zugangsdaten per E-Mail.

Vielen Dank,
{{ company.name }}
MD],
    'invoice.created' => ['Rechnung {{ invoice.number }} ist bereit', <<<'MD'
Hallo {{ client.first_name }},

Rechnung **{{ invoice.number }}** über **{{ invoice.total }}** ist bereit. Sie ist fällig am **{{ invoice.due_date }}**.

[Rechnung ansehen und bezahlen]({{ invoice.url }})

Vielen Dank,
{{ company.name }}
MD],
    'invoice.credit_note' => ['Gutschrift {{ credit_note.number }} zu Rechnung {{ invoice.number }}', <<<'MD'
Hallo {{ client.first_name }},

wir haben die Gutschrift **{{ credit_note.number }}** über **{{ credit_note.total }}** zu Rechnung **{{ invoice.number }}** ausgestellt.

{{ credit_note.note }}

[Rechnung und Gutschrift ansehen]({{ invoice.url }})

{{ company.name }}
MD],
    'invoice.payment_received' => ['Zahlung für Rechnung {{ invoice.number }} erhalten', <<<'MD'
Hallo {{ client.first_name }},

wir haben Ihre Zahlung für Rechnung **{{ invoice.number }}** erhalten. Vielen Dank!

[Rechnung ansehen]({{ invoice.url }})

{{ company.name }}
MD],
    'invoice.autopay_upcoming' => ['Rechnung {{ invoice.number }} wird am {{ charge_date }} automatisch bezahlt', <<<'MD'
Hallo {{ client.first_name }},

am **{{ charge_date }}** belasten wir **{{ invoice.balance }}** für Rechnung **{{ invoice.number }}** über Ihre {{ payment_method.name }}. Sie müssen nichts tun.

[Rechnung ansehen]({{ invoice.url }})

Um eine andere Karte zu nutzen oder automatische Zahlungen auszuschalten, öffnen Sie [Zahlungsmethoden]({{ payment_methods_url }}).

{{ company.name }}
MD],
    'invoice.autopay_failed' => ['Wir konnten Ihre {{ payment_method.name }} für Rechnung {{ invoice.number }} nicht belasten', <<<'MD'
Hallo {{ client.first_name }},

heute haben wir versucht, **{{ invoice.balance }}** für Rechnung **{{ invoice.number }}** über Ihre {{ payment_method.name }} zu belasten, aber es hat nicht funktioniert: {{ failure }} Von Ihrem Konto wurde nichts abgebucht.

{{ next_try }}

[Rechnung bezahlen]({{ invoice.url }})

Wenn Ihre Karte abgelaufen ist oder eine neue Nummer hat, [fügen Sie die neue hinzu]({{ payment_methods_url }}) und wir nutzen sie beim nächsten Versuch.

{{ company.name }}
MD],
    'payment.method_expiring' => ['Ihre gespeicherte Karte läuft bald ab', <<<'MD'
Hallo {{ client.first_name }},

Ihre {{ payment_method.name }}, die Ihre Verlängerungen automatisch bezahlt, läuft Ende {{ payment_method.expires }} ab.

[Fügen Sie Ihre neue Karte hinzu]({{ payment_methods_url }}), damit sich Ihre Verlängerungen weiter von selbst bezahlen.

{{ company.name }}
MD],
    'invoice.reminder' => ['Erinnerung: Rechnung {{ invoice.number }} ist überfällig', <<<'MD'
Hallo {{ client.first_name }},

Rechnung **{{ invoice.number }}** über **{{ invoice.balance }}** war am {{ invoice.due_date }} fällig und ist noch offen.

[Jetzt bezahlen]({{ invoice.url }})

Dienste mit offenen Rechnungen werden nach einigen Tagen gesperrt. Wenn Sie schon bezahlt haben, ignorieren Sie diese E-Mail bitte.

{{ company.name }}
MD],
    'service.welcome' => ['Ihr {{ service.product }} ist bereit', <<<'MD'
Hallo {{ client.first_name }},

Ihr **{{ service.product }}** für **{{ service.domain }}** ist eingerichtet und bereit.

- Benutzername: {{ service.username }}
- Server: {{ service.server }}

Ihr Passwort und eine Anmeldung per Klick in Ihr Control Panel finden Sie in Ihrem Kundenbereich:

[Dienstdetails öffnen]({{ service.url }})

{{ company.name }}
MD],
    'service.suspended' => ['Dienst gesperrt: {{ service.domain }}', <<<'MD'
Hallo {{ client.first_name }},

Ihr **{{ service.product }}** für **{{ service.domain }}** wurde gesperrt.

Grund: {{ reason }}

Bezahlen Sie offene Rechnungen, um ihn automatisch wieder einzuschalten, oder antworten Sie auf diese E-Mail, wenn Sie Hilfe brauchen.

[Zu Ihrem Konto]({{ client_area_url }})

{{ company.name }}
MD],
    'service.plan_changed' => ['Ihr Tarif ist jetzt {{ plan.new }}', <<<'MD'
Hallo {{ client.first_name }},

Ihr Dienst **{{ service.domain }}** ist von **{{ plan.old }}** zu **{{ plan.new }}** gewechselt.

Ab Ihrer nächsten Verlängerung zahlen Sie {{ plan.amount }} ({{ plan.cycle }}). {{ plan.note }}

[Ihren Dienst ansehen]({{ service.url }})

{{ company.name }}
MD],
    'service.unsuspended' => ['Dienst wieder aktiv: {{ service.domain }}', <<<'MD'
Hallo {{ client.first_name }},

gute Nachricht: Ihr **{{ service.product }}** für **{{ service.domain }}** ist wieder aktiv.

{{ company.name }}
MD],
    'domain.registered' => ['Ihre Domain {{ domain.name }} ist registriert', <<<'MD'
Hallo {{ client.first_name }},

gute Nachricht: **{{ domain.name }}** ist jetzt bis **{{ domain.expires_at }}** auf Sie registriert.

Nameserver: {{ domain.nameservers }}

Es kann einige Stunden dauern, bis die Domain überall im Internet funktioniert.

[Ihre Domain verwalten]({{ domain.url }})

{{ company.name }}
MD],
    'domain.transfer_started' => ['Der Transfer von {{ domain.name }} hat begonnen', <<<'MD'
Hallo {{ client.first_name }},

wir haben den Transfer von **{{ domain.name }}** zu uns gestartet. Transfers dauern meist 5 bis 7 Tage.

Ihr aktueller Registrar schickt Ihnen vielleicht eine E-Mail, um den Transfer zu bestätigen. Wenn Sie bestätigen, geht es schneller.

[Den Transfer verfolgen]({{ domain.url }})

{{ company.name }}
MD],
    'domain.renewed' => ['Ihre Domain {{ domain.name }} ist verlängert', <<<'MD'
Hallo {{ client.first_name }},

danke! **{{ domain.name }}** ist verlängert. Sie läuft jetzt am **{{ domain.expires_at }}** ab.

[Ihre Domain verwalten]({{ domain.url }})

{{ company.name }}
MD],
    'domain.expiring' => ['{{ domain.name }} läuft in {{ days_left }} Tagen ab', <<<'MD'
Hallo {{ client.first_name }},

Ihre Domain **{{ domain.name }}** läuft am **{{ domain.expires_at }}** ab und verlängert sich nicht von selbst.

Wenn Sie sie behalten möchten, verlängern Sie sie jetzt. Eine abgelaufene Domain funktioniert nicht mehr, und jemand anderes kann sie registrieren.

[{{ domain.name }} verlängern]({{ domain.url }})

{{ company.name }}
MD],
    'ticket.opened' => ['[Ticket #{{ ticket.number }}] {{ ticket.subject }}', <<<'MD'
Hallo {{ client.first_name }},

wir haben Ihr Ticket erhalten und antworten so schnell wie möglich.

**{{ ticket.subject }}** · {{ ticket.department }}

[Ihr Ticket ansehen]({{ ticket.url }})

{{ company.name }}
MD],
    'ticket.reply' => ['[Ticket #{{ ticket.number }}] Neue Antwort: {{ ticket.subject }}', <<<'MD'
Hallo {{ client.first_name }},

{{ reply.author }} hat auf Ihr Ticket geantwortet:

{{ reply.message }}

[Ticket ansehen und antworten]({{ ticket.url }})

{{ company.name }}
MD],
    'admin.security_alert' => ['Neues Sicherheitsproblem bei {{ company.name }}', <<<'MD'
Hallo {{ staff.name }},

der nächtliche Website-Check hat etwas Neues gefunden, das Sie braucht:

{{ issues }}

Ihre Sicherheitspunktzahl ist **{{ score }} von 100**.

[Website-Zustand öffnen]({{ admin_url }})

Sie erhalten diese E-Mail, weil Sie Sicherheitsprobleme sehen und beheben dürfen. Passwörter und Dateiinhalte stehen nie in diesen E-Mails.
MD],
    'admin.new_order' => ['Neue Bestellung #{{ order.number }} von {{ client.name }}', <<<'MD'
{{ client.name }} ({{ client.email }}) hat Bestellung **#{{ order.number }}** über **{{ order.total }}** aufgegeben.

[Bestellung öffnen]({{ admin_url }})
MD],
    'admin.ticket_opened' => ['Neues Ticket #{{ ticket.number }}: {{ ticket.subject }}', <<<'MD'
{{ client.name }} hat ein Ticket in {{ ticket.department }} eröffnet:

**{{ ticket.subject }}**

{{ reply.message }}

[Jetzt antworten]({{ admin_url }})
MD],
    'admin.ticket_reply' => ['Kunde hat auf Ticket #{{ ticket.number }} geantwortet', <<<'MD'
{{ client.name }} hat auf **{{ ticket.subject }}** geantwortet:

{{ reply.message }}

[Jetzt antworten]({{ admin_url }})
MD],
    'quote.sent' => ['Ihr Angebot {{ quote.number }} von {{ company.name }}', <<<'MD'
Hallo {{ client.first_name }},

hier ist Ihr Angebot **{{ quote.number }}**: {{ quote.subject }}

Gesamt: **{{ quote.total }}**, gültig bis {{ quote.valid_until }}.

[Angebot ansehen und annehmen]({{ quote.url }})

Fragen? Antworten Sie einfach auf diese E-Mail.

{{ company.name }}
MD],
    'admin.quote_accepted' => ['Angebot {{ quote.number }} von {{ client.name }} angenommen', <<<'MD'
{{ client.name }} hat Angebot **{{ quote.number }}** ({{ quote.subject }}) über **{{ quote.total }}** angenommen.

Dafür wurde Rechnung {{ invoice.number }} erstellt.

[Angebot öffnen]({{ admin_url }})
MD],
    'affiliate.commission' => ['Sie haben {{ commission.amount }} durch eine Empfehlung verdient', <<<'MD'
Hallo {{ client.first_name }},

jemand, den Sie an {{ company.name }} empfohlen haben, hat eine Rechnung bezahlt, und Sie haben **{{ commission.amount }}** verdient.

Der Betrag ist ab {{ commission.available_on }} verfügbar. Dann können Sie ihn auf Ihr Guthaben übertragen.

[Ihr Partnerkonto ansehen]({{ affiliate_url }})

{{ company.name }}
MD],
    'marketplace.license' => ['Ihr Lizenzschlüssel für {{ item.name }}', <<<'MD'
Hallo {{ client.first_name }},

danke für den Kauf von **{{ item.name }}**. Hier ist Ihr Lizenzschlüssel:

**{{ license.key }}**

Zum Installieren öffnen Sie Ihren Nuvabill-Adminbereich, gehen zu **Marktplatz**, suchen {{ item.name }}, fügen den Schlüssel ein und klicken auf **Installieren**.

Der Schlüssel ist an die erste Website gebunden, auf der Sie ihn installieren. Sie können ihn in Ihrem Konto auf eine andere Website übertragen.

[Ihr Konto öffnen]({{ service.url }})

{{ company.name }}
MD],
    'marketplace.review' => ['{{ item.name }} {{ item.version }}: {{ review.outcome }}', <<<'MD'
Hallo {{ client.first_name }},

wir haben **{{ item.name }} {{ item.version }}** geprüft. Ergebnis: {{ review.outcome }}.

{{ review.message }}

[Ihr Entwicklerkonto öffnen]({{ developer_url }})

{{ company.name }}
MD],
];
