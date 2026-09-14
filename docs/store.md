# Store

An optional module that sells physical and digital goods and takes payment through
PayPal. Off by default: enable it under **Site Settings → Optional modules**, then
add PayPal credentials under **Admin Menu → Store**.

## Setting it up

1. **Enable the module.** Site Settings → Optional modules → *Store*, save, reload.
   The `store`, `store_products`, `store_orders`, `store_order_items` and
   `store_downloads` tables are created on first use, the same way every other
   module provisions itself.
2. **Connect PayPal.** In the [PayPal Developer dashboard](https://developer.paypal.com/)
   create a REST app and copy its **client ID** and **secret** into
   **Store → PayPal & shipping**. Leave the environment on **Sandbox**.
3. **Set the currency and shipping.** The currency is a three-letter code and must
   be one the PayPal account can accept. Shipping is a single flat rate, charged
   once per order that contains a physical item.
4. **Add products**, then buy one from yourself with a PayPal sandbox account.
5. **Switch to Live** only once a sandbox order has appeared under **Orders** with
   status *Paid*. Live and sandbox credentials are different pairs — switching the
   environment without replacing both will fail to take payment.

## Putting a shop on a page

Edit any page and add the shortcode:

```
[store:all]        the whole catalogue
[store:prints]     one category
```

The cart, checkout and receipt all render in place. There is no separate shop
page to create, and the storefront is public — visitors do not need an account.

## Products

| Field | Notes |
| --- | --- |
| SKU | Unique; appears on the order and in PayPal's line items |
| Price | Entered as `12.50`; stored as integer cents |
| Kind | *Physical* asks for a shipping address and charges shipping; *digital* delivers a download |
| Category | What `[store:CATEGORY]` selects |
| Image URL | Rendered in an `<img src>`, so it is scheme-checked like every other URL the CMS accepts |
| File to deliver | Digital products only: a path **under `files/store/`** that already exists |
| Show in the store | Unticking hides it without deleting it |

Deleting a product does not alter past orders: every line item keeps its own copy
of the name, SKU, kind and price that was actually charged.

## How payment works

```
browser                     this site                        PayPal
   |  cart: product ids + quantities  |                          |
   |--------------------------------->|                          |
   |                                  | price the cart from the  |
   |                                  | database, write a        |
   |                                  | PENDING order            |
   |                                  |------ create order ----->|
   |<-------- PayPal order id --------|                          |
   |------------- buyer approves in PayPal's window ------------>|
   |  capture(order id)               |                          |
   |--------------------------------->|------- capture --------->|
   |                                  |<--- amount + capture id -|
   |                                  | amount and currency must |
   |                                  | match the pending order  |
   |                                  | -> PAID, receipt, links  |
   |<------------ receipt ------------|                          |
```

Two rules hold this together:

**No price ever comes from the browser.** The cart is product ids and quantities.
Every amount — line, subtotal, shipping, total — is looked up from
`store_products` and computed server-side, and that computed total is what PayPal
is asked to charge. There is no input anywhere that accepts a price from a buyer.

**The order is recorded before the money moves.** A `pending` row with the PayPal
order id is written before capture is attempted. If capture succeeds but this
process dies before the row is updated, there is a record to reconcile rather than
a charged customer and nothing to show for it. After capture, the amount and
currency PayPal reports are compared against that row; a mismatch marks the order
`failed` and never marks it paid. A unique index on the PayPal order id, plus an
update conditional on the order still being pending, means a replayed capture
returns the original receipt instead of charging or delivering twice.

`paid` is not a status an administrator can set by hand. Only a verified capture
sets it.

## Digital delivery

A paid order issues one download grant per digital line: a 48-character random
token, tied to that order and product, expiring after the configured window
(default 72 hours) and limited to a number of downloads (default 5). The links
appear on the receipt and in the receipt e-mail.

`mod/store/store.download.php` serves them. It deliberately has **no session
check** — the link has to work from an e-mail days later — so the token is the
whole of the authorisation, and the download is claimed with a conditional update
before the file is sent, so two parallel requests cannot both take the last one.
Everything is sent as `application/octet-stream` with
`Content-Disposition: attachment`: a purchased `.html` or `.svg` rendered inline
would execute in this site's origin.

**Paid files live in `files/store/`, and that directory must stay closed to the
web server.** `files/` itself has to remain readable — gallery images and product
photos are served from it — so a paid file sitting there would be fetchable by
anyone who guessed the name, with no token, expiry or download count involved.
`files/store/.htaccess` denies HTTP access to that subdirectory while PHP keeps
reading it normally. **If this site runs on nginx, or on Apache with
`AllowOverride` off, replicate that deny in the server config** — otherwise the
whole download-token mechanism is decorative. Upload paid files with the file
manager or over the shell; the path you enter on the product is relative to
`files/store/`, so `guide.pdf`, not `files/store/guide.pdf`.

## Orders and fulfilment

Orders appear under **Store → Orders** as soon as PayPal confirms payment, and
every administrator account is e-mailed (the same list the critical alerts use —
see [critical-alerts.md](critical-alerts.md)). Mark a paid order **Shipped** once
it is posted; the customer is not e-mailed automatically at that point. A
**pending** row is a checkout nobody finished — nothing was charged, and it is
safe to leave or cancel.

## Security notes

- **The PayPal secret is stored in the `store` table**, like the SMTP password in
  the mail module. It has to be replayable to authenticate to PayPal, so it cannot
  be hashed. Anyone who can read that table, or a backup of it, can create and
  capture payments against the merchant account. Re-saving the settings form with
  the secret box empty keeps the stored secret; the form never renders it back.
- **The client ID is public** by design — it identifies the merchant in PayPal's
  own button script. Only the secret matters.
- **Enabling this module widens the site's content-security policy.** PayPal's
  button SDK is a third-party script that opens a third-party frame and calls
  PayPal's hosts, so `script-src`, `connect-src` and `frame-src` gain
  `https://www.paypal.com`, `https://www.sandbox.paypal.com` and
  `https://www.paypalobjects.com` while the store is on. Disabling the module
  restores the tighter policy. The SDK is loaded only when a checkout is opened,
  not on ordinary page views.
- **Card details never reach this site.** Payment happens in PayPal's window; this
  app sees an order id, a capture id, an amount, and the payer's e-mail address.
- Cart contents live in the visitor's session as ids and quantities only, so a
  stale cart can never carry a stale price.
- Management endpoints are admin-gated and go through the CSRF-protected RPC
  layer. The storefront and cart endpoints are public by design.

## Files

| File | Role |
| --- | --- |
| `mod/store/store.php` | Module entry point; wires `storedb` + `storeui` |
| `mod/store/store.db.php` | Settings, catalogue, orders, download grants |
| `mod/store/store.paypal.php` | PayPal Orders v2 client (`StorePaypalClient`), injectable transport |
| `mod/store/store.async.php` | Endpoints, `[store:…]` shortcode, `class storeui` |
| `mod/store/store.download.php` | Token-authorised delivery of a purchased file |
| `mod/store/store.js` / `store.css` | Storefront, checkout, PayPal buttons, admin forms |
| `mod/store/store.sql`, `insert.sql` | Schema and the seed settings row |

## Validation

`php tests/store.php` covers price parsing, cart totalling (including that
shipping is charged only for physical items and that a deactivated product cannot
be sold), that the amount sent to PayPal comes from the catalogue rather than from
anything the client sent, that a short or wrong-currency capture never marks an
order paid, that a replayed capture neither pays twice nor issues a second set of
download links, that `paid` cannot be set by hand, that management endpoints
refuse a signed-out caller, that product and customer text is escaped, and that a
digital product's path cannot escape `files/store/`. It also checks that `files/store/` carries its deny rule and
that a product path cannot reach a sibling directory or a dotfile. It needs no
database, no PayPal and no mail server: a fake data layer and a mock transport
stand in for both. `php tests/store-disabled.php` checks that the module is off by
default and that, while off, it registers no endpoints, shows no menu entry, ships
no assets and does not widen the content-security policy.

## Not yet exercised against a live database

The test suite runs against a fake data layer, so `store.sql` and the queries in
`store.db.php` have never been parsed by a real server. Check these first on a
database-backed install: the `insert … on duplicate key update` in `saveSettings`,
the transaction and rollback in `createOrder`, and the unique index on
`paypalOrderId` (which is `not null default ''` — MySQL allows many NULLs but only
one empty string, so it would bite if an order were ever inserted before PayPal
returned an id; today nothing does).

Confirm the PayPal origins in the content-security policy during a sandbox
purchase with the browser console open. A blocked origin fails silently, and
PayPal's SDK pulls from more hosts than its documentation names.

If a download grant fails to insert after payment, the receipt shows fewer links
than digital lines. The order detail screen lists the grants that do exist, so the
fix is manual — nothing is lost, and the payment is unaffected.

## What this module is not

There is no inventory tracking, no tax calculation, no discount codes, no
multi-currency, no refunds from the admin screen (refund in PayPal, then cancel
the order here), and no customer account area. Shipping is one flat rate, not a
table of zones or weights.
