<?php

// Brazilian Portuguese versions of the built-in email templates. Placeholders stay exactly as in English.
return [
    'client.welcome' => ['Boas-vindas à {{ company.name }}', <<<'MD'
Olá, {{ client.first_name }},

Obrigado por criar uma conta na {{ company.name }}.

Entre quando quiser para pedir serviços, pagar faturas e falar com o suporte:

[Ir para sua conta]({{ client_area_url }})

Se você ainda não escolheu uma senha, use “Esqueci minha senha” na página de login.

Obrigado,
{{ company.name }}
MD],
    'client.two_factor_code' => ['Seu código de login da {{ company.name }}', <<<'MD'
Olá, {{ client.first_name }},

Seu código de login é: **{{ code }}**

Ele vale por 10 minutos. Nunca compartilhe este código: nossa equipe nunca vai pedir.

Se não foi você que tentou entrar, troque sua senha agora.

{{ company.name }}
MD],
    'order.confirmation' => ['Pedido nº {{ order.number }} recebido', <<<'MD'
Olá, {{ client.first_name }},

Obrigado pelo seu pedido **nº {{ order.number }}**. O total é **{{ order.total }}**.

[Pagar a fatura {{ invoice.number }}]({{ invoice.url }})

Configuramos seu serviço assim que o pagamento chegar e enviamos os dados por e-mail.

Obrigado,
{{ company.name }}
MD],
    'invoice.created' => ['A fatura {{ invoice.number }} está pronta', <<<'MD'
Olá, {{ client.first_name }},

A fatura **{{ invoice.number }}** de **{{ invoice.total }}** está pronta. O vencimento é em **{{ invoice.due_date }}**.

[Ver e pagar a fatura]({{ invoice.url }})

Obrigado,
{{ company.name }}
MD],
    'invoice.credit_note' => ['Nota de crédito {{ credit_note.number }} da fatura {{ invoice.number }}', <<<'MD'
Olá, {{ client.first_name }}!

Emitimos a nota de crédito **{{ credit_note.number }}** de **{{ credit_note.total }}** referente à fatura **{{ invoice.number }}**.

{{ credit_note.note }}

[Ver a fatura e a nota de crédito]({{ invoice.url }})

{{ company.name }}
MD],
    'invoice.payment_received' => ['Pagamento recebido da fatura {{ invoice.number }}', <<<'MD'
Olá, {{ client.first_name }},

Recebemos seu pagamento da fatura **{{ invoice.number }}**. Obrigado!

[Ver a fatura]({{ invoice.url }})

{{ company.name }}
MD],
    'invoice.autopay_upcoming' => ['A fatura {{ invoice.number }} será paga automaticamente em {{ charge_date }}', <<<'MD'
Olá, {{ client.first_name }},

Em **{{ charge_date }}** vamos cobrar **{{ invoice.balance }}** da fatura **{{ invoice.number }}** no seu {{ payment_method.name }}. Você não precisa fazer nada.

[Ver a fatura]({{ invoice.url }})

Para usar outro cartão ou desligar os pagamentos automáticos, abra [Formas de pagamento]({{ payment_methods_url }}).

{{ company.name }}
MD],
    'invoice.autopay_failed' => ['Não conseguimos cobrar seu {{ payment_method.name }} pela fatura {{ invoice.number }}', <<<'MD'
Olá, {{ client.first_name }},

Hoje tentamos cobrar **{{ invoice.balance }}** da fatura **{{ invoice.number }}** no seu {{ payment_method.name }}, mas não funcionou: {{ failure }} Nada foi cobrado da sua conta.

{{ next_try }}

[Pagar a fatura]({{ invoice.url }})

Se o seu cartão venceu ou tem um número novo, [adicione o novo]({{ payment_methods_url }}) e vamos usá-lo na próxima tentativa.

{{ company.name }}
MD],
    'payment.method_expiring' => ['Seu cartão salvo vence em breve', <<<'MD'
Olá, {{ client.first_name }},

Seu {{ payment_method.name }}, que paga suas renovações automaticamente, vence no fim de {{ payment_method.expires }}.

[Adicione seu novo cartão]({{ payment_methods_url }}) para que suas renovações continuem se pagando sozinhas.

{{ company.name }}
MD],
    'invoice.reminder' => ['Lembrete: a fatura {{ invoice.number }} está vencida', <<<'MD'
Olá, {{ client.first_name }},

A fatura **{{ invoice.number }}** de **{{ invoice.balance }}** venceu em {{ invoice.due_date }} e ainda não foi paga.

[Pagar agora]({{ invoice.url }})

Serviços com faturas em aberto são suspensos depois de alguns dias. Se você já pagou, ignore este e-mail.

{{ company.name }}
MD],
    'service.welcome' => ['Seu {{ service.product }} está pronto', <<<'MD'
Olá, {{ client.first_name }},

Seu **{{ service.product }}** para **{{ service.domain }}** está configurado e pronto para usar.

- Usuário: {{ service.username }}
- Servidor: {{ service.server }}

Sua senha e o acesso com um clique ao painel de controle estão na sua área do cliente:

[Abrir os dados do serviço]({{ service.url }})

{{ company.name }}
MD],
    'service.suspended' => ['Serviço suspenso: {{ service.domain }}', <<<'MD'
Olá, {{ client.first_name }},

Seu **{{ service.product }}** para **{{ service.domain }}** foi suspenso.

Motivo: {{ reason }}

Pague as faturas em aberto para reativá-lo automaticamente, ou responda a este e-mail se precisar de ajuda.

[Ir para sua conta]({{ client_area_url }})

{{ company.name }}
MD],
    'service.plan_changed' => ['Seu plano agora é {{ plan.new }}', <<<'MD'
Olá, {{ client.first_name }},

Seu serviço **{{ service.domain }}** mudou de **{{ plan.old }}** para **{{ plan.new }}**.

A partir da próxima renovação você paga {{ plan.amount }} ({{ plan.cycle }}). {{ plan.note }}

[Ver seu serviço]({{ service.url }})

{{ company.name }}
MD],
    'service.unsuspended' => ['Serviço ativo de novo: {{ service.domain }}', <<<'MD'
Olá, {{ client.first_name }},

Boa notícia: seu **{{ service.product }}** para **{{ service.domain }}** está ativo de novo.

{{ company.name }}
MD],
    'domain.registered' => ['Seu domínio {{ domain.name }} está registrado', <<<'MD'
Olá, {{ client.first_name }},

Boa notícia: **{{ domain.name }}** agora está registrado em seu nome até **{{ domain.expires_at }}**.

Servidores DNS: {{ domain.nameservers }}

Pode levar algumas horas até o domínio funcionar em toda a internet.

[Gerenciar seu domínio]({{ domain.url }})

{{ company.name }}
MD],
    'domain.transfer_started' => ['A transferência de {{ domain.name }} começou', <<<'MD'
Olá, {{ client.first_name }},

Começamos a transferência de **{{ domain.name }}** para nós. As transferências costumam levar de 5 a 7 dias.

Seu registrador atual pode enviar um e-mail pedindo para aprovar a transferência. Aprovar deixa tudo mais rápido.

[Acompanhar a transferência]({{ domain.url }})

{{ company.name }}
MD],
    'domain.renewed' => ['Seu domínio {{ domain.name }} foi renovado', <<<'MD'
Olá, {{ client.first_name }},

Obrigado! **{{ domain.name }}** foi renovado. Agora ele vence em **{{ domain.expires_at }}**.

[Gerenciar seu domínio]({{ domain.url }})

{{ company.name }}
MD],
    'domain.expiring' => ['{{ domain.name }} vence em {{ days_left }} dias', <<<'MD'
Olá, {{ client.first_name }},

Seu domínio **{{ domain.name }}** vence em **{{ domain.expires_at }}** e não vai renovar sozinho.

Se quiser mantê-lo, renove agora. Um domínio vencido para de funcionar e outra pessoa pode registrá-lo.

[Renovar {{ domain.name }}]({{ domain.url }})

{{ company.name }}
MD],
    'ticket.opened' => ['[Ticket nº {{ ticket.number }}] {{ ticket.subject }}', <<<'MD'
Olá, {{ client.first_name }},

Recebemos seu ticket e vamos responder o mais rápido possível.

**{{ ticket.subject }}** · {{ ticket.department }}

[Ver seu ticket]({{ ticket.url }})

{{ company.name }}
MD],
    'ticket.reply' => ['[Ticket nº {{ ticket.number }}] Nova resposta: {{ ticket.subject }}', <<<'MD'
Olá, {{ client.first_name }},

{{ reply.author }} respondeu ao seu ticket:

{{ reply.message }}

[Ver o ticket e responder]({{ ticket.url }})

{{ company.name }}
MD],
    'admin.security_alert' => ['Novo problema de segurança em {{ company.name }}', <<<'MD'
Olá, {{ staff.name }},

A verificação de saúde do site desta noite encontrou algo novo que precisa de você:

{{ issues }}

Sua nota de segurança é **{{ score }} de 100**.

[Abrir a saúde do site]({{ admin_url }})

Você recebe este e-mail porque pode ver e corrigir problemas de segurança. Senhas e conteúdo de arquivos nunca aparecem nestes e-mails.
MD],
    'admin.new_order' => ['Novo pedido nº {{ order.number }} de {{ client.name }}', <<<'MD'
{{ client.name }} ({{ client.email }}) fez o pedido **nº {{ order.number }}** de **{{ order.total }}**.

[Abrir o pedido]({{ admin_url }})
MD],
    'admin.ticket_opened' => ['Novo ticket nº {{ ticket.number }}: {{ ticket.subject }}', <<<'MD'
{{ client.name }} abriu um ticket em {{ ticket.department }}:

**{{ ticket.subject }}**

{{ reply.message }}

[Responder agora]({{ admin_url }})
MD],
    'admin.ticket_reply' => ['O cliente respondeu ao ticket nº {{ ticket.number }}', <<<'MD'
{{ client.name }} respondeu a **{{ ticket.subject }}**:

{{ reply.message }}

[Responder agora]({{ admin_url }})
MD],
    'quote.sent' => ['Seu orçamento {{ quote.number }} da {{ company.name }}', <<<'MD'
Olá, {{ client.first_name }},

Aqui está seu orçamento **{{ quote.number }}**: {{ quote.subject }}

Total: **{{ quote.total }}**, válido até {{ quote.valid_until }}.

[Ver e aceitar o orçamento]({{ quote.url }})

Dúvidas? É só responder a este e-mail.

{{ company.name }}
MD],
    'admin.quote_accepted' => ['Orçamento {{ quote.number }} aceito por {{ client.name }}', <<<'MD'
{{ client.name }} aceitou o orçamento **{{ quote.number }}** ({{ quote.subject }}) de **{{ quote.total }}**.

A fatura {{ invoice.number }} foi criada para ele.

[Abrir o orçamento]({{ admin_url }})
MD],
    'affiliate.commission' => ['Você ganhou {{ commission.amount }} com uma indicação', <<<'MD'
Olá, {{ client.first_name }},

Alguém que você indicou para a {{ company.name }} pagou uma fatura, e você ganhou **{{ commission.amount }}**.

O valor fica disponível em {{ commission.available_on }}. Aí você pode passá-lo para sua carteira.

[Ver sua conta de afiliado]({{ affiliate_url }})

{{ company.name }}
MD],
    'marketplace.license' => ['Sua chave de licença de {{ item.name }}', <<<'MD'
Olá, {{ client.first_name }},

Obrigado por comprar **{{ item.name }}**. Esta é sua chave de licença:

**{{ license.key }}**

Para instalar, abra a área administrativa do Nuvabill, vá em **Marketplace**, encontre {{ item.name }}, cole a chave e clique em **Instalar**.

A chave fica ligada ao primeiro site em que você instalar. Você pode movê-la para outro site pela sua conta.

[Abrir sua conta]({{ service.url }})

{{ company.name }}
MD],
    'marketplace.review' => ['{{ item.name }} {{ item.version }}: {{ review.outcome }}', <<<'MD'
Olá, {{ client.first_name }},

Analisamos **{{ item.name }} {{ item.version }}**. Resultado: {{ review.outcome }}.

{{ review.message }}

[Abrir sua conta de desenvolvedor]({{ developer_url }})

{{ company.name }}
MD],
];
