# Spec: repeated order submit (double click) finishes the checkout (CS-8633)

> **Retroactive.** Reconstructed on 2026-11-06 from the implementation (`87c6fe3`, README `e7b740b`).
> Decisions marked *unconfirmed* were read from the code, not from a recorded interview.
> **Status: awaiting developer confirmation.**

## Problem and goal

Gambio finishes the checkout (confirmation mail, success page) when `checkout_process.php` is
submitted while the order of the current checkout already exists. A double click on the order button,
or Back from the Mollie page followed by another submit, therefore finishes the checkout before the
payment. Goal: only the return from Mollie may finish the checkout; any other submit continues the
payment.

## Scope

- All Mollie payment modules (base class `mollie`).
- Double click on the order button and resubmit of the confirmation form after Back from Mollie.

## Out of scope

- Changing Gambio's checkout or theme (button disabling).
- Shops whose session handler does not serialize requests of one session.

## Acceptance criteria

1. Two near-simultaneous submits of the confirmation form create **one** order and **one** Mollie
   payment; both responses redirect to the same Mollie checkout; no confirmation mail.
2. Submitting the confirmation form again after reaching Mollie redirects to the same Mollie checkout;
   no new order, no mail.
3. After paying, the return finishes the checkout once: success page, one confirmation mail.
4. A canceled payment after a repeated submit still leads back to payment selection.

## Decisions

| Decision | Why |
|---|---|
| Guard in `mollie::before_process()` | Gambio calls it on every submit before deciding to finish; module-local, no overload, works across GX versions |
| The return sets a session mark (`mollie_returned_order` = order id) that `before_process()` consumes once | Distinguishes the legitimate return from a repeated submit without changing Gambio; single-use per ASVS 7.1 |
| A repeated submit redirects to the Mollie checkout link stored with the payment (`OrderReference` payload) | The shopper continues the same payment; no second payment is created |
| Without a stored checkout link, redirect to `MollieCheckoutRedirect` for the order | Lets the return logic decide by the payment status (for example a paid card payment without a checkout link) |

## Port to `4.1-4.4`

Ported in release 2.1.5 on branch `feature/CS-8633` (`39ad983`; README note in `52ea8b4`). Not yet verified on a GX 4.1–4.4 shop; the
guard relies on Gambio calling `before_process()` before the `tmp_oID` check, which is still to be
confirmed for GX 4.1–4.4 (`tasks.md` ROS-9).

## Open decisions

None beyond the open tasks (`tasks.md` ROS-5).

## Open risks

- **Payment creation failed + double click:** the first request's `payment_action()` fails and has no
  `OrderReference`; the second request is sent to `MollieCheckoutRedirect`, which (status created, no
  reference, no sync) sets the returned mark and finishes the checkout with a confirmation mail.
  Found in review, **not reproduced** (testing skipped by the developer's choice).
- `getPaymentContinuationUrl()` returns the checkout link stored at creation without checking the
  payment status. For an expired or canceled payment Mollie is expected to redirect back to the
  return URL; not verified.
- Requires per-session request serialization (PHP file sessions). A non-locking session handler can
  still create two orders on a double click.
