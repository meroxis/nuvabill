<?php

// French versions of the built-in email templates. Placeholders stay exactly as in English.
return [
    'client.welcome' => ['Bienvenue chez {{ company.name }}', <<<'MD'
Bonjour {{ client.first_name }},

Merci d’avoir créé un compte chez {{ company.name }}.

Connectez-vous à tout moment pour commander des services, payer vos factures et contacter le support :

[Accéder à votre compte]({{ client_area_url }})

Si vous n’avez pas encore choisi de mot de passe, utilisez « Mot de passe oublié » sur la page de connexion.

Merci,
{{ company.name }}
MD],
    'client.two_factor_code' => ['Votre code de connexion pour {{ company.name }}', <<<'MD'
Bonjour {{ client.first_name }},

Votre code de connexion est : **{{ code }}**

Il est valable 10 minutes. Ne partagez jamais ce code : notre équipe ne vous le demandera jamais.

Si vous n’avez pas essayé de vous connecter, changez votre mot de passe maintenant.

{{ company.name }}
MD],
    'order.confirmation' => ['Commande n° {{ order.number }} reçue', <<<'MD'
Bonjour {{ client.first_name }},

Merci pour votre commande **n° {{ order.number }}**. Le total est de **{{ order.total }}**.

[Payer la facture {{ invoice.number }}]({{ invoice.url }})

Nous installons votre service dès réception du paiement et vous envoyons les détails par e-mail.

Merci,
{{ company.name }}
MD],
    'invoice.created' => ['La facture {{ invoice.number }} est prête', <<<'MD'
Bonjour {{ client.first_name }},

La facture **{{ invoice.number }}** d’un montant de **{{ invoice.total }}** est prête. Elle est à régler avant le **{{ invoice.due_date }}**.

[Voir et payer la facture]({{ invoice.url }})

Merci,
{{ company.name }}
MD],
    'invoice.credit_note' => ['Avoir {{ credit_note.number }} pour la facture {{ invoice.number }}', <<<'MD'
Bonjour {{ client.first_name }},

Nous avons émis l’avoir **{{ credit_note.number }}** de **{{ credit_note.total }}** sur la facture **{{ invoice.number }}**.

{{ credit_note.note }}

[Voir la facture et l’avoir]({{ invoice.url }})

{{ company.name }}
MD],
    'invoice.payment_received' => ['Paiement reçu pour la facture {{ invoice.number }}', <<<'MD'
Bonjour {{ client.first_name }},

Nous avons bien reçu votre paiement pour la facture **{{ invoice.number }}**. Merci !

[Voir la facture]({{ invoice.url }})

{{ company.name }}
MD],
    'invoice.autopay_upcoming' => ['La facture {{ invoice.number }} sera payée automatiquement le {{ charge_date }}', <<<'MD'
Bonjour {{ client.first_name }},

Le **{{ charge_date }}**, nous prélèverons **{{ invoice.balance }}** pour la facture **{{ invoice.number }}** sur votre {{ payment_method.name }}. Vous n’avez rien à faire.

[Voir la facture]({{ invoice.url }})

Pour utiliser une autre carte ou désactiver les paiements automatiques, ouvrez [Moyens de paiement]({{ payment_methods_url }}).

{{ company.name }}
MD],
    'invoice.autopay_failed' => ['Nous n’avons pas pu débiter votre {{ payment_method.name }} pour la facture {{ invoice.number }}', <<<'MD'
Bonjour {{ client.first_name }},

Aujourd’hui, nous avons essayé de prélever **{{ invoice.balance }}** pour la facture **{{ invoice.number }}** sur votre {{ payment_method.name }}, mais cela n’a pas fonctionné : {{ failure }} Rien n’a été prélevé sur votre compte.

{{ next_try }}

[Payer la facture]({{ invoice.url }})

Si votre carte a expiré ou a un nouveau numéro, [ajoutez la nouvelle]({{ payment_methods_url }}) et nous l’utiliserons pour le prochain essai.

{{ company.name }}
MD],
    'payment.method_expiring' => ['Votre carte enregistrée expire bientôt', <<<'MD'
Bonjour {{ client.first_name }},

Votre {{ payment_method.name }}, qui paie automatiquement vos renouvellements, expire à la fin de {{ payment_method.expires }}.

[Ajoutez votre nouvelle carte]({{ payment_methods_url }}) pour que vos renouvellements continuent de se payer tout seuls.

{{ company.name }}
MD],
    'invoice.reminder' => ['Rappel : la facture {{ invoice.number }} est en retard', <<<'MD'
Bonjour {{ client.first_name }},

La facture **{{ invoice.number }}** d’un montant de **{{ invoice.balance }}** était à régler le {{ invoice.due_date }} et n’est toujours pas payée.

[Payer maintenant]({{ invoice.url }})

Les services avec des factures impayées sont suspendus après quelques jours. Si vous avez déjà payé, ignorez cet e-mail.

{{ company.name }}
MD],
    'service.welcome' => ['Votre {{ service.product }} est prêt', <<<'MD'
Bonjour {{ client.first_name }},

Votre **{{ service.product }}** pour **{{ service.domain }}** est installé et prêt à l’emploi.

- Nom d’utilisateur : {{ service.username }}
- Serveur : {{ service.server }}

Votre mot de passe et une connexion en un clic au panneau de contrôle sont dans votre espace client :

[Ouvrir les détails du service]({{ service.url }})

{{ company.name }}
MD],
    'service.suspended' => ['Service suspendu : {{ service.domain }}', <<<'MD'
Bonjour {{ client.first_name }},

Votre **{{ service.product }}** pour **{{ service.domain }}** a été suspendu.

Raison : {{ reason }}

Payez les factures en attente pour le réactiver automatiquement, ou répondez à cet e-mail si vous avez besoin d’aide.

[Accéder à votre compte]({{ client_area_url }})

{{ company.name }}
MD],
    'service.plan_changed' => ['Votre offre est maintenant {{ plan.new }}', <<<'MD'
Bonjour {{ client.first_name }},

Votre service **{{ service.domain }}** est passé de **{{ plan.old }}** à **{{ plan.new }}**.

À partir de votre prochain renouvellement, vous payez {{ plan.amount }} ({{ plan.cycle }}). {{ plan.note }}

[Voir votre service]({{ service.url }})

{{ company.name }}
MD],
    'service.unsuspended' => ['Service de nouveau actif : {{ service.domain }}', <<<'MD'
Bonjour {{ client.first_name }},

Bonne nouvelle : votre **{{ service.product }}** pour **{{ service.domain }}** est de nouveau actif.

{{ company.name }}
MD],
    'domain.registered' => ['Votre domaine {{ domain.name }} est enregistré', <<<'MD'
Bonjour {{ client.first_name }},

Bonne nouvelle : **{{ domain.name }}** est maintenant enregistré à votre nom jusqu’au **{{ domain.expires_at }}**.

Serveurs de noms : {{ domain.nameservers }}

Quelques heures peuvent être nécessaires avant que le domaine fonctionne partout sur Internet.

[Gérer votre domaine]({{ domain.url }})

{{ company.name }}
MD],
    'domain.transfer_started' => ['Le transfert de {{ domain.name }} a commencé', <<<'MD'
Bonjour {{ client.first_name }},

Nous avons lancé le transfert de **{{ domain.name }}** chez nous. Un transfert prend en général 5 à 7 jours.

Votre registraire actuel peut vous envoyer un e-mail pour approuver le transfert. L’approuver le rend plus rapide.

[Suivre le transfert]({{ domain.url }})

{{ company.name }}
MD],
    'domain.renewed' => ['Votre domaine {{ domain.name }} est renouvelé', <<<'MD'
Bonjour {{ client.first_name }},

Merci ! **{{ domain.name }}** est renouvelé. Il expire maintenant le **{{ domain.expires_at }}**.

[Gérer votre domaine]({{ domain.url }})

{{ company.name }}
MD],
    'domain.expiring' => ['{{ domain.name }} expire dans {{ days_left }} jours', <<<'MD'
Bonjour {{ client.first_name }},

Votre domaine **{{ domain.name }}** expire le **{{ domain.expires_at }}** et ne se renouvellera pas tout seul.

Si vous voulez le garder, renouvelez-le maintenant. Un domaine expiré ne fonctionne plus et quelqu’un d’autre peut l’enregistrer.

[Renouveler {{ domain.name }}]({{ domain.url }})

{{ company.name }}
MD],
    'ticket.opened' => ['[Ticket n° {{ ticket.number }}] {{ ticket.subject }}', <<<'MD'
Bonjour {{ client.first_name }},

Nous avons bien reçu votre ticket et vous répondrons dès que possible.

**{{ ticket.subject }}** · {{ ticket.department }}

[Voir votre ticket]({{ ticket.url }})

{{ company.name }}
MD],
    'ticket.reply' => ['[Ticket n° {{ ticket.number }}] Nouvelle réponse : {{ ticket.subject }}', <<<'MD'
Bonjour {{ client.first_name }},

{{ reply.author }} a répondu à votre ticket :

{{ reply.message }}

[Voir le ticket et répondre]({{ ticket.url }})

{{ company.name }}
MD],
    'admin.security_alert' => ['Nouveau problème de sécurité sur {{ company.name }}', <<<'MD'
Bonjour {{ staff.name }},

Le contrôle de santé du site de cette nuit a trouvé quelque chose de nouveau qui demande votre attention :

{{ issues }}

Votre score de sécurité est de **{{ score }} sur 100**.

[Ouvrir la santé du site]({{ admin_url }})

Vous recevez cet e-mail car vous pouvez voir et corriger les problèmes de sécurité. Les mots de passe et le contenu des fichiers ne figurent jamais dans ces e-mails.
MD],
    'admin.new_order' => ['Nouvelle commande n° {{ order.number }} de {{ client.name }}', <<<'MD'
{{ client.name }} ({{ client.email }}) a passé la commande **n° {{ order.number }}** pour **{{ order.total }}**.

[Ouvrir la commande]({{ admin_url }})
MD],
    'admin.ticket_opened' => ['Nouveau ticket n° {{ ticket.number }} : {{ ticket.subject }}', <<<'MD'
{{ client.name }} a ouvert un ticket dans {{ ticket.department }} :

**{{ ticket.subject }}**

{{ reply.message }}

[Répondre maintenant]({{ admin_url }})
MD],
    'admin.ticket_reply' => ['Le client a répondu au ticket n° {{ ticket.number }}', <<<'MD'
{{ client.name }} a répondu à **{{ ticket.subject }}** :

{{ reply.message }}

[Répondre maintenant]({{ admin_url }})
MD],
    'quote.sent' => ['Votre devis {{ quote.number }} de {{ company.name }}', <<<'MD'
Bonjour {{ client.first_name }},

Voici votre devis **{{ quote.number }}** : {{ quote.subject }}

Total : **{{ quote.total }}**, valable jusqu’au {{ quote.valid_until }}.

[Voir et accepter le devis]({{ quote.url }})

Des questions ? Répondez simplement à cet e-mail.

{{ company.name }}
MD],
    'admin.quote_accepted' => ['Devis {{ quote.number }} accepté par {{ client.name }}', <<<'MD'
{{ client.name }} a accepté le devis **{{ quote.number }}** ({{ quote.subject }}) pour **{{ quote.total }}**.

La facture {{ invoice.number }} a été créée pour ce devis.

[Ouvrir le devis]({{ admin_url }})
MD],
    'affiliate.commission' => ['Vous avez gagné {{ commission.amount }} grâce à une recommandation', <<<'MD'
Bonjour {{ client.first_name }},

Une personne que vous avez recommandée à {{ company.name }} a payé une facture, et vous avez gagné **{{ commission.amount }}**.

Le montant sera disponible le {{ commission.available_on }}. Vous pourrez alors le transférer dans votre portefeuille.

[Voir votre compte d’affiliation]({{ affiliate_url }})

{{ company.name }}
MD],
    'marketplace.license' => ['Votre clé de licence {{ item.name }}', <<<'MD'
Bonjour {{ client.first_name }},

Merci d’avoir acheté **{{ item.name }}**. Voici votre clé de licence :

**{{ license.key }}**

Pour l’installer, ouvrez l’administration de Nuvabill, allez dans **Marketplace**, trouvez {{ item.name }}, collez la clé et cliquez sur **Installer**.

La clé est liée au premier site sur lequel vous l’installez. Vous pouvez la déplacer vers un autre site depuis votre compte.

[Ouvrir votre compte]({{ service.url }})

{{ company.name }}
MD],
    'marketplace.review' => ['{{ item.name }} {{ item.version }} : {{ review.outcome }}', <<<'MD'
Bonjour {{ client.first_name }},

Nous avons examiné **{{ item.name }} {{ item.version }}**. Résultat : {{ review.outcome }}.

{{ review.message }}

[Ouvrir votre compte développeur]({{ developer_url }})

{{ company.name }}
MD],
];
