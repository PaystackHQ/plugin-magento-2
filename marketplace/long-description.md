# Marketplace Long Description

Record of the copy for the Adobe Commerce Marketplace listing's **Long
Description** field, structured to Adobe's
[product description guidelines](https://developer.adobe.com/commerce/marketplace/guides/sellers/product-descriptions).

> **This is a record, not a paste source.** The Marketplace editor requires
> bullets be created with its own toolbar bullet button and headings be set
> with its style dropdown. Pasting Markdown produces text that merely looks
> like a list — precisely what failed marketing review in August 2026.
> Retype into the editor; do not paste.

This extension is subject to **three** overlapping rule sets, because it is a
standard extension, an integration extension, and a payment extension at once:

| Rule set | Requires |
|---|---|
| Standard | Opening paragraph with no heading, then a `Features` section (H2/H3), then any further sections |
| Integration | A company-background paragraph before the opening paragraph, the first company mention as a **bold hyperlink**, and an `Account and Pricing` section |
| Payment | Security and PCI compliance information, including how customer data is handled, stored and transmitted |

## Section order

Adobe specifies that additional headed sections come **after** Features. The
live listing currently has Account & Pricing before Features, which is the
wrong way round.

```
1. Company background paragraph      (no heading)
2. Product opening paragraph(s)      (no heading)
3. Features                          H3   <- must come first among headings
4. Account and Pricing               H3
5. Security and PCI Compliance       H3
6. Additional Links                  H3
```

---

## Copy

**[Paystack](https://paystack.com)** builds technology to help Africa's best businesses grow - from new startups to market leaders launching new business models. We make it easy for businesses to accept secure payments from multiple local and global payment channels, and then we provide tools to help you retain existing customers, and acquire new ones. Using Paystack, you can easily accept payments for your customers with no setup, periodic or maintenance fees.

The Paystack Payments extension for Magento 2 provides a beautiful, intuitive payment experience that allows your customers to pay you via multiple payment channels. When the Paystack Payments extension is enabled, customers will have the option of making a payment to your business at checkout either using their debit/credit card, bank account number, USSD, mobile money, or Visa QR. **This extension is free to use. Paystack charges a per-transaction fee on each successful payment, with no setup, monthly, or hidden fees.**

With Paystack, you can either choose for your customers to be redirected to a new page where they can enter their payment information, or have an iFrame modal where they can input their payment information without leaving the page. Once the requested information is entered, the payment is successful and the order on Magento is completed.

### Features

- A delightful, seamless payment experience
- Accept payments from MasterCard, Visa, and Verve cards
- Accept payments via USSD, Visa QR, bank accounts, and mobile money
- Support for popular Magento one step checkout extensions
- Phenomenal transaction success rates
- Detailed reporting for accounting, reconciliation, and audits
- Allow customers to pay you either without leaving your site or on a hosted Paystack checkout

### Account and Pricing

To use the Paystack Payments extension for Magento, you'll need to have a Paystack business. If you don't have this set up, please [sign up for an account](https://dashboard.paystack.com/#/signup).

While this extension is free to use, Paystack charges a small fee on every transaction that is successfully processed. You can find full details on our [pricing page](https://paystack.com/pricing). There are no setup, monthly, or hidden fees.

For more information on how to get started on Paystack, please visit our [Help Center](https://paystack.com/help).

### Security and PCI Compliance

Paystack is a PCI-certified, auditor certified, PCI Service Provider Level 1 - the highest certification level. All connections to our services are forced to happen over HTTPS using TLS 1.2 (SSL). Card details are encrypted using AES-256 GCM while the decryption keys are stored on a separate machine. As such, cards are not stored as plain numbers but securely hidden even from Paystack personnel and systems.

Using the Paystack Payments extension for Magento, you will have the option of letting customers pay you via the Paystack checkout right on your site, or to be redirected to a hosted Paystack URL where they can complete their payments. In both cases, Paystack provides a PCI-compliant checkout that is completely secure.

At no point is a customer's payment information entered directly on your site or stored on your site or on Magento's servers. All payment processes happen only on Paystack's servers which guarantees maximum security.

### Additional Links

- [How to install](https://support.paystack.com/en/articles/2126530)
- [Developer documentation](https://developers.paystack.co/v2.0/docs/)
- [Support](https://paystack.com/contact)

---

## Editor checklist

Required by the guidelines, in the order they are easiest to apply:

- [ ] Move the **Features** section above Account and Pricing. Additional headed sections must follow Features.
- [ ] Rename `Features & Benefits` to `Features` and set it to **Header 3**. The reviewer asked literally for a "Features" heading.
- [ ] Delete the existing feature lines and re-enter all seven with the editor's **bullet button**. Minimum is five; seven satisfies it.
- [ ] Make the first `Paystack` mention a **bold hyperlink** to https://paystack.com. Required for integration extensions and currently plain text.
- [ ] Add the bold third-party fee sentence to the opening paragraph. Adobe requires third-party service fees to appear there in bold.
- [ ] Hyperlink every URL. The live copy says "sign up for an account here", "our pricing here" and "Help Center" as plain text; non-hyperlinked URLs are prohibited.
- [ ] Rename `Account & Pricing` to `Account and Pricing`, matching Adobe's own section name.
- [ ] Set every section heading to **Header 3**, in black or orange only.

Checked and already compliant — do not "fix" these:

- No occurrences of "This Magento extension"; the copy already uses "extension for Magento".
- "Magento" is capitalised throughout. Lowercase "m" is prohibited.
- No installation instructions in the long description; the install link is a link only.
- No stylised fonts or colours outside headings.

Optional, not required:

- **Demo links** are recommended and absent. If added, they need labels and sign-in credentials where relevant.

## Candidates for a future update

Real capabilities absent from the current bullets. Held back deliberately —
adding copy during a rejection cycle creates new surface to fail on:

- Automatic order confirmation via webhooks, so orders are marked paid even if
  the customer closes their browser
- Test and live mode with separate API key pairs for safe integration testing
- Retry flow that restores the customer's cart after a failed or abandoned
  payment
- Compatible with Magento Open Source and Adobe Commerce 2.4.x on PHP 8.2+
