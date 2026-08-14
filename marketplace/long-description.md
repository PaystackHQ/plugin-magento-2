# Marketplace Long Description

Record of the copy in the Adobe Commerce Marketplace listing's **Long
Description** field. Kept here so the next listing update starts from version
control rather than from a Slack scroll.

> **This is a record, not a paste source.** The Marketplace editor requires
> that bullets be created with its own toolbar bullet button, and that the
> "Features" heading be set to Header 2 or Header 3 with its style dropdown.
> Pasting Markdown produces text that merely looks like a list — that is
> precisely what failed marketing review in August 2026. Retype into the
> editor; do not paste.

---

Paystack builds technology to help Africa's best businesses grow - from new startups to market leaders launching new business models. We make it easy for businesses to accept secure payments from multiple local and global payment channels, and then we provide tools to help you retain existing customers, and acquire new ones. Using Paystack, you can easily accept payments for your customers with no setup, periodic or maintenance fees.

The Paystack Payments extension for Magento 2 provides a beautiful, intuitive payment experience that allows your customers to pay you via multiple payment channels. When the Paystack Payments extension is enabled, customers will have the option of making a payment to your business at checkout either using their debit/credit card, bank account number, USSD, mobile money, or Visa QR.

With Paystack, you can either choose for your customers to be redirected to a new page where they can enter their payment information, or have an iFrame modal where they can input their payment information without leaving the page. Once the requested information is entered, the payment is successful and the order on Magento is completed.

### Account & Pricing

To use the Paystack Payments extension for Magento, you'll need to have a Paystack business. If you don't have this set up, please sign up for an account here.

While this extension is free to use, Paystack charges a small fee on every transaction that is successfully processed. You can find full details of our pricing here. There are no setup, monthly, or hidden fees.

For more information on how to get started on Paystack, please visit our Help Center.

### Features

<!-- Heading must be Header 2 or Header 3 in the editor.
     Bullets below must be entered with the editor's bullet button. -->

- A delightful, seamless payment experience
- Accept payments from MasterCard, Visa, and Verve cards
- Accept payments via USSD, Visa QR, bank accounts, and mobile money
- Support for popular Magento one step checkout extensions
- Phenomenal transaction success rates
- Detailed reporting for accounting, reconciliation, and audits
- Allow customers to pay you either without leaving your site or on a hosted Paystack checkout

### Security & PCI Compliance

Paystack is a PCI-certified, auditor certified, PCI Service Provider Level 1 - the highest certification level. All connections to our services are forced to happen over HTTPS using TLS 1.2 (SSL). Card details are encrypted using AES-256 GCM while the decryption keys are stored on a separate machine. As such, cards are not stored as plain numbers but securely hidden even from Paystack personnel and systems.

Using the Paystack Payments extension for Magento, you will have the option of letting customers pay you via the Paystack checkout right on your site, or to be redirected to a hosted Paystack URL where they can complete their payments. In both cases, Paystack provides a PCI-compliant checkout that is completely secure.

At no point is a customer's payment information entered directly on your site or stored on your site or on Magento's servers. All payment processes happen only on Paystack's servers which guarantees maximum security.

### Additional Links

- How to install
- Developer documentation
- Support

---

## Changes pending in the editor

Not yet applied to the live listing as of the August 2026 marketing-review
rejection:

- Rename the `Features & Benefits` heading to `Features`, set to Header 3.
  The reviewer asked literally for a "Features" heading; "Features & Benefits"
  is a coin-flip on whether it satisfies them.
- Delete the seven feature lines and re-enter them with the editor's bullet
  button. Trailing periods and stray spacing normalised above.

## Candidates for a future update

Real capabilities absent from the current bullets. Held back deliberately —
adding copy during a rejection cycle creates new surface to fail on:

- Automatic order confirmation via webhooks, so orders are marked paid even if
  the customer closes their browser
- Test and live mode with separate API key pairs for safe integration testing
- Retry flow that restores the customer's cart after a failed or abandoned
  payment
- Compatible with Magento Open Source and Adobe Commerce 2.4.x on PHP 8.2+
