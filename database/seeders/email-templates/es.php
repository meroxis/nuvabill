<?php

// Spanish versions of the built-in email templates. Placeholders stay exactly as in English.
return [
    'client.welcome' => ['Te damos la bienvenida a {{ company.name }}', <<<'MD'
Hola, {{ client.first_name }}:

Gracias por crear una cuenta en {{ company.name }}.

Inicia sesión cuando quieras para pedir servicios, pagar facturas y contactar con soporte:

[Ir a tu cuenta]({{ client_area_url }})

Si aún no has elegido una contraseña, usa “¿Olvidaste tu contraseña?” en la página de inicio de sesión.

Gracias,
{{ company.name }}
MD],
    'client.two_factor_code' => ['Tu código de inicio de sesión para {{ company.name }}', <<<'MD'
Hola, {{ client.first_name }}:

Tu código de inicio de sesión es: **{{ code }}**

Sirve durante 10 minutos. No compartas nunca este código: nuestro equipo nunca te lo pedirá.

Si no intentaste iniciar sesión, cambia tu contraseña ahora.

{{ company.name }}
MD],
    'order.confirmation' => ['Pedido n.º {{ order.number }} recibido', <<<'MD'
Hola, {{ client.first_name }}:

Gracias por tu pedido **n.º {{ order.number }}**. El total es **{{ order.total }}**.

[Pagar la factura {{ invoice.number }}]({{ invoice.url }})

Preparamos tu servicio en cuanto llegue el pago y te enviamos los datos por correo.

Gracias,
{{ company.name }}
MD],
    'invoice.created' => ['La factura {{ invoice.number }} está lista', <<<'MD'
Hola, {{ client.first_name }}:

La factura **{{ invoice.number }}** por **{{ invoice.total }}** está lista. Vence el **{{ invoice.due_date }}**.

[Ver y pagar la factura]({{ invoice.url }})

Gracias,
{{ company.name }}
MD],
    'invoice.payment_received' => ['Pago recibido para la factura {{ invoice.number }}', <<<'MD'
Hola, {{ client.first_name }}:

Hemos recibido tu pago de la factura **{{ invoice.number }}**. ¡Gracias!

[Ver la factura]({{ invoice.url }})

{{ company.name }}
MD],
    'invoice.autopay_upcoming' => ['La factura {{ invoice.number }} se pagará automáticamente el {{ charge_date }}', <<<'MD'
Hola, {{ client.first_name }}:

El **{{ charge_date }}** cobraremos **{{ invoice.balance }}** de la factura **{{ invoice.number }}** a tu {{ payment_method.name }}. No tienes que hacer nada.

[Ver la factura]({{ invoice.url }})

Para usar otra tarjeta o desactivar los pagos automáticos, abre [Métodos de pago]({{ payment_methods_url }}).

{{ company.name }}
MD],
    'invoice.autopay_failed' => ['No pudimos cobrar a tu {{ payment_method.name }} la factura {{ invoice.number }}', <<<'MD'
Hola, {{ client.first_name }}:

Hoy intentamos cobrar **{{ invoice.balance }}** de la factura **{{ invoice.number }}** a tu {{ payment_method.name }}, pero no funcionó: {{ failure }} No se ha cobrado nada de tu cuenta.

{{ next_try }}

[Pagar la factura]({{ invoice.url }})

Si tu tarjeta ha caducado o tiene un número nuevo, [añade la nueva]({{ payment_methods_url }}) y la usaremos en el siguiente intento.

{{ company.name }}
MD],
    'payment.method_expiring' => ['Tu tarjeta guardada caduca pronto', <<<'MD'
Hola, {{ client.first_name }}:

Tu {{ payment_method.name }}, que paga tus renovaciones automáticamente, caduca a finales de {{ payment_method.expires }}.

[Añade tu nueva tarjeta]({{ payment_methods_url }}) para que tus renovaciones sigan pagándose solas.

{{ company.name }}
MD],
    'invoice.reminder' => ['Recordatorio: la factura {{ invoice.number }} está vencida', <<<'MD'
Hola, {{ client.first_name }}:

La factura **{{ invoice.number }}** por **{{ invoice.balance }}** vencía el {{ invoice.due_date }} y sigue sin pagar.

[Pagar ahora]({{ invoice.url }})

Los servicios con facturas sin pagar se suspenden a los pocos días. Si ya has pagado, ignora este correo.

{{ company.name }}
MD],
    'service.welcome' => ['Tu {{ service.product }} está listo', <<<'MD'
Hola, {{ client.first_name }}:

Tu **{{ service.product }}** para **{{ service.domain }}** está configurado y listo para usar.

- Usuario: {{ service.username }}
- Servidor: {{ service.server }}

Tu contraseña y el acceso con un clic al panel de control están en tu área de clientes:

[Abrir los datos del servicio]({{ service.url }})

{{ company.name }}
MD],
    'service.suspended' => ['Servicio suspendido: {{ service.domain }}', <<<'MD'
Hola, {{ client.first_name }}:

Tu **{{ service.product }}** para **{{ service.domain }}** se ha suspendido.

Motivo: {{ reason }}

Paga las facturas pendientes para reactivarlo automáticamente, o responde a este correo si necesitas ayuda.

[Ir a tu cuenta]({{ client_area_url }})

{{ company.name }}
MD],
    'service.plan_changed' => ['Tu plan ahora es {{ plan.new }}', <<<'MD'
Hola, {{ client.first_name }}:

Tu servicio **{{ service.domain }}** ha pasado de **{{ plan.old }}** a **{{ plan.new }}**.

Desde tu próxima renovación pagas {{ plan.amount }} ({{ plan.cycle }}). {{ plan.note }}

[Ver tu servicio]({{ service.url }})

{{ company.name }}
MD],
    'service.unsuspended' => ['Servicio activo de nuevo: {{ service.domain }}', <<<'MD'
Hola, {{ client.first_name }}:

Buenas noticias: tu **{{ service.product }}** para **{{ service.domain }}** vuelve a estar activo.

{{ company.name }}
MD],
    'domain.registered' => ['Tu dominio {{ domain.name }} está registrado', <<<'MD'
Hola, {{ client.first_name }}:

Buenas noticias: **{{ domain.name }}** ya está registrado a tu nombre hasta el **{{ domain.expires_at }}**.

Servidores de nombres: {{ domain.nameservers }}

El dominio puede tardar unas horas en funcionar en todo internet.

[Gestionar tu dominio]({{ domain.url }})

{{ company.name }}
MD],
    'domain.transfer_started' => ['La transferencia de {{ domain.name }} ha empezado', <<<'MD'
Hola, {{ client.first_name }}:

Hemos empezado la transferencia de **{{ domain.name }}** a nosotros. Las transferencias suelen tardar de 5 a 7 días.

Tu registrador actual puede enviarte un correo para aprobar la transferencia. Si la apruebas, irá más rápido.

[Seguir la transferencia]({{ domain.url }})

{{ company.name }}
MD],
    'domain.renewed' => ['Tu dominio {{ domain.name }} está renovado', <<<'MD'
Hola, {{ client.first_name }}:

¡Gracias! **{{ domain.name }}** está renovado. Ahora caduca el **{{ domain.expires_at }}**.

[Gestionar tu dominio]({{ domain.url }})

{{ company.name }}
MD],
    'domain.expiring' => ['{{ domain.name }} caduca en {{ days_left }} días', <<<'MD'
Hola, {{ client.first_name }}:

Tu dominio **{{ domain.name }}** caduca el **{{ domain.expires_at }}** y no se renovará solo.

Si quieres conservarlo, renuévalo ahora. Un dominio caducado deja de funcionar y otra persona puede registrarlo.

[Renovar {{ domain.name }}]({{ domain.url }})

{{ company.name }}
MD],
    'ticket.opened' => ['[Ticket n.º {{ ticket.number }}] {{ ticket.subject }}', <<<'MD'
Hola, {{ client.first_name }}:

Hemos recibido tu ticket y te responderemos lo antes posible.

**{{ ticket.subject }}** · {{ ticket.department }}

[Ver tu ticket]({{ ticket.url }})

{{ company.name }}
MD],
    'ticket.reply' => ['[Ticket n.º {{ ticket.number }}] Nueva respuesta: {{ ticket.subject }}', <<<'MD'
Hola, {{ client.first_name }}:

{{ reply.author }} ha respondido a tu ticket:

{{ reply.message }}

[Ver el ticket y responder]({{ ticket.url }})

{{ company.name }}
MD],
    'admin.security_alert' => ['Nuevo problema de seguridad en {{ company.name }}', <<<'MD'
Hola, {{ staff.name }}:

La revisión nocturna de la salud del sitio ha encontrado algo nuevo que necesita tu atención:

{{ issues }}

Tu puntuación de seguridad es **{{ score }} de 100**.

[Abrir la salud del sitio]({{ admin_url }})

Recibes este correo porque puedes ver y corregir problemas de seguridad. Nunca se incluyen contraseñas ni contenido de archivos en estos correos.
MD],
    'admin.new_order' => ['Nuevo pedido n.º {{ order.number }} de {{ client.name }}', <<<'MD'
{{ client.name }} ({{ client.email }}) ha hecho el pedido **n.º {{ order.number }}** por **{{ order.total }}**.

[Abrir el pedido]({{ admin_url }})
MD],
    'admin.ticket_opened' => ['Nuevo ticket n.º {{ ticket.number }}: {{ ticket.subject }}', <<<'MD'
{{ client.name }} ha abierto un ticket en {{ ticket.department }}:

**{{ ticket.subject }}**

{{ reply.message }}

[Responder ahora]({{ admin_url }})
MD],
    'admin.ticket_reply' => ['El cliente respondió al ticket n.º {{ ticket.number }}', <<<'MD'
{{ client.name }} ha respondido a **{{ ticket.subject }}**:

{{ reply.message }}

[Responder ahora]({{ admin_url }})
MD],
    'quote.sent' => ['Tu presupuesto {{ quote.number }} de {{ company.name }}', <<<'MD'
Hola, {{ client.first_name }}:

Aquí tienes tu presupuesto **{{ quote.number }}**: {{ quote.subject }}

Total: **{{ quote.total }}**, válido hasta el {{ quote.valid_until }}.

[Ver y aceptar el presupuesto]({{ quote.url }})

¿Tienes preguntas? Responde a este correo.

{{ company.name }}
MD],
    'admin.quote_accepted' => ['{{ client.name }} aceptó el presupuesto {{ quote.number }}', <<<'MD'
{{ client.name }} ha aceptado el presupuesto **{{ quote.number }}** ({{ quote.subject }}) por **{{ quote.total }}**.

Se ha creado la factura {{ invoice.number }} para él.

[Abrir el presupuesto]({{ admin_url }})
MD],
    'affiliate.commission' => ['Has ganado {{ commission.amount }} por una recomendación', <<<'MD'
Hola, {{ client.first_name }}:

Alguien a quien recomendaste {{ company.name }} ha pagado una factura y has ganado **{{ commission.amount }}**.

Estará disponible el {{ commission.available_on }}. Entonces podrás pasarlo a tu monedero.

[Ver tu cuenta de afiliado]({{ affiliate_url }})

{{ company.name }}
MD],
    'marketplace.license' => ['Tu clave de licencia de {{ item.name }}', <<<'MD'
Hola, {{ client.first_name }}:

Gracias por comprar **{{ item.name }}**. Esta es tu clave de licencia:

**{{ license.key }}**

Para instalarlo, abre la administración de Nuvabill, ve a **Marketplace**, busca {{ item.name }}, pega la clave y pulsa **Instalar**.

La clave queda ligada al primer sitio donde la instales. Puedes moverla a otro sitio desde tu cuenta.

[Abrir tu cuenta]({{ service.url }})

{{ company.name }}
MD],
    'marketplace.review' => ['{{ item.name }} {{ item.version }}: {{ review.outcome }}', <<<'MD'
Hola, {{ client.first_name }}:

Hemos revisado **{{ item.name }} {{ item.version }}**. Resultado: {{ review.outcome }}.

{{ review.message }}

[Abrir tu cuenta de desarrollador]({{ developer_url }})

{{ company.name }}
MD],
];
