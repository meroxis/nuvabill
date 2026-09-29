# AI help

Nuvabill can use **Claude by Anthropic** to draft ticket replies, translate tickets both ways, summarize tickets and
write product texts. It uses **your own Anthropic key**: you pay Anthropic for what you use, and Nuvabill adds nothing.
Staff always check and send every reply. AI never answers a client by itself.

## Set it up

1. Make an API key at [console.anthropic.com](https://console.anthropic.com/) → **API keys**, and add some credit there.
2. In Nuvabill, open **Settings → AI**, paste the key and press **Save settings**. The key is stored encrypted.
3. Press **Test the key**. It sends one tiny request with the saved key and model.

Staff see AI buttons when their role has the right **Use AI help for ticket replies and product texts**. Roles that
answer tickets, edit products or manage settings get it when you update to 0.5.1; change it in **Settings → Roles**.

## Pick a model

| Model | Good for | Anthropic's price per million tokens (read / written) |
|---|---|---|
| **Fast and low cost** · Claude Haiku 4.5 | Summaries, translations and most replies. The default. | $1 / $5 |
| **Best answers** · Claude Sonnet 5.5 | Hard technical tickets | $2 / $10 |
| **Most capable** · Claude Opus 5.5 | The hardest tickets. Takes longer. | $4 / $20 |

A token is about ¾ of a word. A typical reply draft reads a few thousand tokens and writes a few hundred, so it costs
well under a cent with Haiku 4.5. If Sonnet 5.5 or Opus 5.5 declines to answer something, Anthropic lets another model
answer instead of returning nothing.

## Spending limit

Set a **monthly limit** in US dollars (default $20; 0 means no limit). Nuvabill logs what every AI request cost, shows
the total for this month in Settings → AI, emails your company address once when spending passes 80%, and pauses AI help
until the next month when the limit is reached.

## On the ticket page

- **Write a draft**: Claude writes a reply from the whole ticket, the client's first name and language, their service
  and invoice status. Then **Shorter**, **Friendlier** or **More detail** rewrite the current draft, and **Ask AI**
  takes an instruction, for example "offer to fix it for them and mention our backups". The draft is in your team's
  language; you edit it and send it.
- **AI summary**: one to three sentences about the ticket, with a suggested department and priority. It is kept on the
  ticket, and the page tells you when new messages arrived since.
- **What drafts are based on** lists the facts the AI sees.

## Tickets in two languages

Choose the language your team reads and writes in Settings → AI.

- **Client messages** in another language are translated in the background, so your team sees them translated, with
  **Show the original**. English messages to an English team are not sent to the AI at all. A **Translate** button
  appears on a message that may be in another language but was not translated.
- **Your reply** can go out in the client's language (the language of their last message). Tick **Send it in …**, and
  the first press shows the translation to check; the second press sends exactly that. If you change your reply after
  that, it is translated again when you send. The ticket shows what you wrote, with **What the client got** below.

## Product pages

**Write with AI** under the product description writes the store description (one feature per line) and the title and
description for search results, in your store's main language, from the product's name, group, type, current
description and lowest price. You can add what to stress, for example "for small online shops". Check the texts, then
save the product.

## What is shared with the AI

| Shared | Never shared |
|---|---|
| Ticket messages | Passwords and API keys |
| The client's first name and language | Card and bank details |
| Product and service names, domains | Email addresses and phone numbers |
| Service and invoice status | Client addresses and payment history |

Before anything goes to the AI, Nuvabill takes out email addresses, phone numbers, card and bank account numbers,
passwords written after a label (like "password: …"), private keys and long secret keys, and puts markers like
`[email]` in their place. Dates, IP addresses, ticket and invoice numbers stay, because answers need them. When your
reply contains such details and is translated, they are masked before and put back after, so they never reach the AI.

The prompts tell Claude that ticket text is written by clients and is data, not instructions to follow.

## The demo

The public demo has no key: every AI button shows a sample answer, so you can see how it works. One demo ticket is
written in Arabic to show translation.
