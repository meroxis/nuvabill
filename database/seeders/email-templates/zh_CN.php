<?php

// Simplified Chinese versions of the built-in email templates. Placeholders stay exactly as in English.
return [
    'client.welcome' => ['欢迎来到 {{ company.name }}', <<<'MD'
{{ client.first_name }}，你好：

感谢你在 {{ company.name }} 创建账户。

你可以随时登录，订购服务、支付发票和联系支持：

[前往你的账户]({{ client_area_url }})

如果你还没有设置密码，请在登录页面使用“忘记密码”。

谢谢，
{{ company.name }}
MD],
    'client.two_factor_code' => ['你的 {{ company.name }} 登录验证码', <<<'MD'
{{ client.first_name }}，你好：

你的登录验证码是：**{{ code }}**

验证码 10 分钟内有效。请勿把它告诉任何人：我们的员工绝不会向你索要。

如果不是你本人在尝试登录，请立即修改密码。

{{ company.name }}
MD],
    'order.confirmation' => ['已收到订单 #{{ order.number }}', <<<'MD'
{{ client.first_name }}，你好：

感谢你的订单 **#{{ order.number }}**。总额为 **{{ order.total }}**。

[支付发票 {{ invoice.number }}]({{ invoice.url }})

收到付款后，我们会立即为你开通服务，并通过邮件发送详细信息。

谢谢，
{{ company.name }}
MD],
    'invoice.created' => ['发票 {{ invoice.number }} 已生成', <<<'MD'
{{ client.first_name }}，你好：

金额为 **{{ invoice.total }}** 的发票 **{{ invoice.number }}** 已生成，付款截止日期为 **{{ invoice.due_date }}**。

[查看并支付发票]({{ invoice.url }})

谢谢，
{{ company.name }}
MD],
    'invoice.credit_note' => ['发票 {{ invoice.number }} 的贷项通知单 {{ credit_note.number }}', <<<'MD'
{{ client.first_name }}，您好：

我们已为发票 **{{ invoice.number }}** 开具贷项通知单 **{{ credit_note.number }}**，金额为 **{{ credit_note.total }}**。

{{ credit_note.note }}

[查看发票和贷项通知单]({{ invoice.url }})

{{ company.name }}
MD],
    'invoice.payment_received' => ['已收到发票 {{ invoice.number }} 的付款', <<<'MD'
{{ client.first_name }}，你好：

我们已收到你对发票 **{{ invoice.number }}** 的付款。谢谢！

[查看发票]({{ invoice.url }})

{{ company.name }}
MD],
    'invoice.autopay_upcoming' => ['发票 {{ invoice.number }} 将于 {{ charge_date }} 自动支付', <<<'MD'
{{ client.first_name }}，你好：

我们将于 **{{ charge_date }}** 从你的 {{ payment_method.name }} 扣取发票 **{{ invoice.number }}** 的 **{{ invoice.balance }}**。你无需做任何操作。

[查看发票]({{ invoice.url }})

如需更换银行卡或关闭自动付款，请打开[付款方式]({{ payment_methods_url }})。

{{ company.name }}
MD],
    'invoice.autopay_failed' => ['无法从你的 {{ payment_method.name }} 扣取发票 {{ invoice.number }} 的款项', <<<'MD'
{{ client.first_name }}，你好：

今天我们尝试从你的 {{ payment_method.name }} 扣取发票 **{{ invoice.number }}** 的 **{{ invoice.balance }}**，但未成功：{{ failure }} 你的账户没有被扣款。

{{ next_try }}

[支付发票]({{ invoice.url }})

如果你的银行卡已过期或换了新卡号，请[添加新卡]({{ payment_methods_url }})，我们会在下次尝试时使用它。

{{ company.name }}
MD],
    'payment.method_expiring' => ['你保存的银行卡即将过期', <<<'MD'
{{ client.first_name }}，你好：

用于自动支付续费的 {{ payment_method.name }} 将于 {{ payment_method.expires }} 底过期。

请[添加新银行卡]({{ payment_methods_url }})，让续费继续自动支付。

{{ company.name }}
MD],
    'invoice.reminder' => ['提醒：发票 {{ invoice.number }} 已逾期', <<<'MD'
{{ client.first_name }}，你好：

金额为 **{{ invoice.balance }}** 的发票 **{{ invoice.number }}** 已于 {{ invoice.due_date }} 到期，目前仍未支付。

[立即支付]({{ invoice.url }})

有未付发票的服务会在几天后被暂停。如果你已经付款，请忽略这封邮件。

{{ company.name }}
MD],
    'service.welcome' => ['你的 {{ service.product }} 已就绪', <<<'MD'
{{ client.first_name }}，你好：

你为 **{{ service.domain }}** 购买的 **{{ service.product }}** 已开通，可以开始使用了。

- 用户名：{{ service.username }}
- 服务器：{{ service.server }}

密码和一键登录控制面板的入口都在你的客户中心：

[打开服务详情]({{ service.url }})

{{ company.name }}
MD],
    'service.suspended' => ['服务已暂停：{{ service.domain }}', <<<'MD'
{{ client.first_name }}，你好：

你为 **{{ service.domain }}** 购买的 **{{ service.product }}** 已被暂停。

原因：{{ reason }}

支付所有未付发票后服务会自动恢复；如需帮助，请直接回复这封邮件。

[前往你的账户]({{ client_area_url }})

{{ company.name }}
MD],
    'service.plan_changed' => ['你的套餐现在是 {{ plan.new }}', <<<'MD'
{{ client.first_name }}，你好：

你的服务 **{{ service.domain }}** 已从 **{{ plan.old }}** 更换为 **{{ plan.new }}**。

从下次续费起，你将支付 {{ plan.amount }}（{{ plan.cycle }}）。{{ plan.note }}

[查看你的服务]({{ service.url }})

{{ company.name }}
MD],
    'service.unsuspended' => ['服务已恢复：{{ service.domain }}', <<<'MD'
{{ client.first_name }}，你好：

好消息：你为 **{{ service.domain }}** 购买的 **{{ service.product }}** 已恢复正常。

{{ company.name }}
MD],
    'domain.registered' => ['你的域名 {{ domain.name }} 已注册', <<<'MD'
{{ client.first_name }}，你好：

好消息：**{{ domain.name }}** 已注册在你名下，有效期至 **{{ domain.expires_at }}**。

域名服务器：{{ domain.nameservers }}

域名在整个互联网生效可能需要几个小时。

[管理你的域名]({{ domain.url }})

{{ company.name }}
MD],
    'domain.transfer_started' => ['{{ domain.name }} 的转移已开始', <<<'MD'
{{ client.first_name }}，你好：

我们已开始把 **{{ domain.name }}** 转移到我们这里。转移通常需要 5 到 7 天。

你当前的注册商可能会发邮件请你批准转移。批准后转移会更快。

[跟进转移进度]({{ domain.url }})

{{ company.name }}
MD],
    'domain.renewed' => ['你的域名 {{ domain.name }} 已续费', <<<'MD'
{{ client.first_name }}，你好：

谢谢！**{{ domain.name }}** 已续费，现在的到期日为 **{{ domain.expires_at }}**。

[管理你的域名]({{ domain.url }})

{{ company.name }}
MD],
    'domain.expiring' => ['{{ domain.name }} 将在 {{ days_left }} 天后到期', <<<'MD'
{{ client.first_name }}，你好：

你的域名 **{{ domain.name }}** 将于 **{{ domain.expires_at }}** 到期，且不会自动续费。

如果想保留它，请立即续费。到期的域名会停止工作，而且可能被别人注册。

[续费 {{ domain.name }}]({{ domain.url }})

{{ company.name }}
MD],
    'ticket.opened' => ['[工单 #{{ ticket.number }}] {{ ticket.subject }}', <<<'MD'
{{ client.first_name }}，你好：

我们已收到你的工单，会尽快回复。

**{{ ticket.subject }}** · {{ ticket.department }}

[查看你的工单]({{ ticket.url }})

{{ company.name }}
MD],
    'ticket.reply' => ['[工单 #{{ ticket.number }}] 新回复：{{ ticket.subject }}', <<<'MD'
{{ client.first_name }}，你好：

{{ reply.author }} 回复了你的工单：

{{ reply.message }}

[查看工单并回复]({{ ticket.url }})

{{ company.name }}
MD],
    'admin.security_alert' => ['{{ company.name }} 发现新的安全问题', <<<'MD'
{{ staff.name }}，你好：

今晚的站点健康检查发现了需要你处理的新问题：

{{ issues }}

你的安全得分为 **{{ score }} / 100**。

[打开站点健康]({{ admin_url }})

你收到这封邮件，是因为你有权查看和修复安全问题。这类邮件绝不会包含密码或文件内容。
MD],
    'admin.new_order' => ['来自 {{ client.name }} 的新订单 #{{ order.number }}', <<<'MD'
{{ client.name }}（{{ client.email }}）提交了订单 **#{{ order.number }}**，金额 **{{ order.total }}**。

[打开订单]({{ admin_url }})
MD],
    'admin.ticket_opened' => ['新工单 #{{ ticket.number }}：{{ ticket.subject }}', <<<'MD'
{{ client.name }} 在 {{ ticket.department }} 提交了工单：

**{{ ticket.subject }}**

{{ reply.message }}

[立即回复]({{ admin_url }})
MD],
    'admin.ticket_reply' => ['客户回复了工单 #{{ ticket.number }}', <<<'MD'
{{ client.name }} 回复了 **{{ ticket.subject }}**：

{{ reply.message }}

[立即回复]({{ admin_url }})
MD],
    'quote.sent' => ['来自 {{ company.name }} 的报价 {{ quote.number }}', <<<'MD'
{{ client.first_name }}，你好：

这是你的报价 **{{ quote.number }}**：{{ quote.subject }}

总额：**{{ quote.total }}**，有效期至 {{ quote.valid_until }}。

[查看并接受报价]({{ quote.url }})

有问题？直接回复这封邮件即可。

{{ company.name }}
MD],
    'admin.quote_accepted' => ['{{ client.name }} 接受了报价 {{ quote.number }}', <<<'MD'
{{ client.name }} 接受了报价 **{{ quote.number }}**（{{ quote.subject }}），金额 **{{ quote.total }}**。

已为其创建发票 {{ invoice.number }}。

[打开报价]({{ admin_url }})
MD],
    'affiliate.commission' => ['你通过推荐赚取了 {{ commission.amount }}', <<<'MD'
{{ client.first_name }}，你好：

你推荐给 {{ company.name }} 的人支付了一张发票，你赚取了 **{{ commission.amount }}**。

这笔金额将于 {{ commission.available_on }} 可用，届时你可以把它转入钱包。

[查看你的推广账户]({{ affiliate_url }})

{{ company.name }}
MD],
    'marketplace.license' => ['你的 {{ item.name }} 许可证密钥', <<<'MD'
{{ client.first_name }}，你好：

感谢购买 **{{ item.name }}**。这是你的许可证密钥：

**{{ license.key }}**

安装方法：打开 Nuvabill 管理后台，进入 **应用市场**，找到 {{ item.name }}，粘贴密钥并点击 **安装**。

密钥会绑定到你首次安装的网站。你可以在账户中把它转移到其他网站。

[打开你的账户]({{ service.url }})

{{ company.name }}
MD],
    'marketplace.review' => ['{{ item.name }} {{ item.version }}：{{ review.outcome }}', <<<'MD'
{{ client.first_name }}，你好：

我们审核了 **{{ item.name }} {{ item.version }}**。结果：{{ review.outcome }}。

{{ review.message }}

[打开你的开发者账户]({{ developer_url }})

{{ company.name }}
MD],
];
