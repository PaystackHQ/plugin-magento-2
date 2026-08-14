# Paystack Payments for Magento 2

User Guide

<!-- Version is injected from composer.json at build time. Never hardcode it here. -->

This guide covers configuring and operating the extension. For installation
instructions, see the Installation Guide. For configuration paths, routes and
event names, see the Reference Manual.

## Enable and Configure

In the Magento Admin, go to **Stores > Configuration > Sales > Payment Methods** and open the **Paystack** section.

- **Enabled** — set to *Yes* to offer Paystack at checkout. Off by default after installation.
- **Title** — the label customers see at checkout. Defaults to *Credit/Debit Cards (powered by Paystack)*.
- **Integration Type** — *Inline* or *Redirect*. See below.
- **Test Mode** — *Yes* uses your test keys and processes no real money. *No* uses your live keys.
- **Test Secret Key** and **Test Public Key** — used when Test Mode is *Yes*.
- **Live Secret Key** and **Live Public Key** — used when Test Mode is *No*.
- **Debug** — writes extra diagnostic detail to the Magento logs.
- **Sort Order** — position of Paystack among your other payment methods at checkout.
- **Payment from Applicable Countries** — restrict Paystack to specific billing countries.
- **Minimum Order Total** and **Maximum Order Total** — order totals outside this range will not see Paystack at checkout.

Click **Save Config**, then flush the cache if prompted.

Secret keys are stored encrypted by Magento and are never displayed again after saving.

## Choose an Integration Type

**Inline** opens the Paystack payment window over your store. The customer enters their details and completes payment without leaving your site, then returns straight to the order success page. This is the default and suits most stores.

**Redirect** sends the customer to a Paystack-hosted payment page and returns them to your store when payment finishes. Choose this if your theme or a third-party checkout extension interferes with the inline window.

Both are fully PCI-compliant. Card details are never entered on, or stored by, your store — they are captured by Paystack directly.

If a customer abandons a redirect payment, they are returned to the checkout payment step with their cart intact so they can try again.

## Test Before Going Live

Leave **Test Mode** set to *Yes* and enter your test API keys. Place an order using Paystack's test card details, published in the [Paystack test-payments documentation](https://paystack.com/docs/payments/test-payments/). No money moves, and the order behaves exactly as it will in production.

Check that the order reaches **Processing** and that the confirmation email is sent.

When you are ready to accept real payments, set **Test Mode** to *No*, enter your live keys, save, and place one small live order to confirm the end-to-end flow.

## Set Up Webhooks

A webhook lets Paystack notify your store directly when a payment succeeds, so orders are confirmed even if the customer closes their browser before returning. This matters most with the Redirect integration type.

1. In your Paystack dashboard, go to **Settings > API Keys & Webhooks**.
2. Set the Webhook URL to `https://yourdomain.com/paystack/payment/webhook`.
3. Save.

Your webhook URL is also shown in the Magento Admin at the top of the Paystack configuration section.

The URL must be reachable over HTTPS from the public internet. Webhooks cannot reach a store running on `localhost` or behind a private network.

## What Customers See

Paystack appears at the checkout payment step using the **Title** you configured, positioned according to **Sort Order**. Customers can pay by card, bank account, USSD, mobile money, or QR, depending on which channels are enabled on your Paystack account.

Paystack is offered on the storefront only. It is intentionally unavailable when creating orders from the Magento Admin, because those orders are placed without a customer present to authorise a payment.

## How Orders Progress

1. The customer places the order. It is created in **Pending** status and no confirmation email is sent yet.
2. The customer completes payment with Paystack.
3. The extension verifies the payment directly with Paystack — it never trusts the browser's word that payment succeeded.
4. On successful verification the order moves to **Processing** and the order confirmation email is sent.

Holding the confirmation email until payment is verified means customers are never emailed about an order that was not paid for.

If verification does not complete — the customer closed the browser, or the network dropped — the webhook confirms the payment independently and moves the order forward.

## Troubleshooting

**Paystack does not appear at checkout.** Confirm **Enabled** is *Yes*, that both the public and secret key are filled in for the current mode, and that the order total falls between your configured minimum and maximum. Then run `php bin/magento cache:flush`.

**Orders stay in Pending after a successful payment.** Check that the webhook URL is set correctly in your Paystack dashboard and is reachable over HTTPS from the public internet.

**Payments succeed but no order appears.** Confirm the keys in Magento belong to the same Paystack business that processed the payment, and that **Test Mode** matches the key pair you entered — live keys with Test Mode on, or test keys with it off, will not verify.

**The inline window does not open.** Switch **Integration Type** to *Redirect* to confirm the rest of the flow works. If Redirect succeeds, a theme or checkout extension is likely blocking the inline window.

**Customers report being charged without an order.** Verify the payment reference in your Paystack dashboard, then check the webhook delivery log there. Paystack retries failed webhook deliveries.

## Support

For issues with this extension, use the [issue tracker](https://github.com/PaystackHQ/plugin-magento-2/issues).

For questions about your Paystack account, send a message from [our website](https://paystack.com/contact).
