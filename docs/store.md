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
| Fulfilled by | *You*, or a supplier — see [Dropshipping](#dropshipping). A supplier-fulfilled item is still physical |
| Category | What `[store:CATEGORY]` selects |
| Image URL | Rendered in an `<img src>`, so it is scheme-checked like every other URL the CMS accepts |
| File to deliver | Digital products only: a path **under `files/store/`** that already exists |
| Featured | Pins the product ahead of other products in the default collection order |
| Original price | Optional higher price shown crossed out beside the selling price; blank clears it |
| Product badge | Optional short label, such as “New arrival” or “Limited edition” |
| Delivery note | Optional product-specific timing or delivery details |
| Spring product URL | Shown when Spring / Teespring is selected; links directly to hosted checkout |
| Show in the store | Unticking hides it without deleting it |

Deleting a product does not alter past orders: every line item keeps its own copy
of the name, SKU, kind and price that was actually charged.

## Browsing and merchandising

The responsive collection includes product search (name, description, or SKU),
category chips, physical/digital/Spring filters, and sorting by featured, newest,
price, or name. Controls are independent when multiple `[store:…]` shortcodes
appear on one page. Product details expand in place; missing images get a styled
placeholder. Light/dark appearance, keyboard focus, and reduced-motion preferences
are supported.

Products can be featured, carry a short badge and delivery note, and show a sale
price beside an optional higher original price. The admin catalogue includes live,
featured, and Spring counts, thumbnails, and search. **Duplicate** copies product
fields into a hidden draft with a blank SKU, useful for related products or separate
size/colour SKUs. Review the name, price, and supplier mapping before saving it.
This does not introduce automatic variant or inventory synchronization.

Existing installations gain `externalUrl`, `featured`, `compareAtCents`, `badge`,
and `deliveryNote` through the normal additive module schema upgrade. No existing
products change fulfilment route; defaults preserve their current behaviour.

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

## Dropshipping

A product can be fulfilled by you or handed to a supplier. Mark it **Fulfilled by →
a supplier** under Products, choose the supplier, and give it that supplier's ids;
when an order containing it is paid, it is queued and sent automatically.

### Suppliers

| Supplier | Credentials | Mapping on the product |
| --- | --- | --- |
| **Printful** | Private token (Settings → Developers); store id only on a multi-store account | Catalogue `variant_id`, or a sync variant id from a connected store |
| **Printify** | Personal access token (My profile → Connections) and shop id from `GET /v1/shops.json` | Both product id and variant id |
| **CJ Dropshipping** | Account e-mail and API key (Authorization → API) | Variant id (`vid`) |
| **Generic webhook** | An endpoint URL you control, and optionally a signing secret and a status URL | Whatever ids your receiver expects — passed straight through |

The webhook adapter is how a supplier without a public order API gets connected:
this site POSTs the paid order as JSON to your endpoint — your own middleware, an
automation platform, a supplier's private integration — and that endpoint does the
talking. With a signing secret set, the request carries `X-Ghoti-Timestamp` and
`X-Ghoti-Signature: sha256=<hmac>` over `timestamp.body`, so the receiver can prove
it came from this site and reject a replay.

### Spring / Teespring: paste the item URL

1. Add a product under **Store → Products** (name, SKU, starting price, image,
   and description for your local catalogue).
2. Choose **Fulfilled by → Spring / Teespring**.
3. Paste the item's HTTPS URL from `creator-spring.com`, `teespring.com`, or
   `spri.ng` into **Spring product URL**, then save.

The card shows **Buy on Spring**. That link opens the item on Spring, where the
customer selects sizes/colours and checks out; Spring handles production and
fulfilment. No API key, supplier product ID, or variant ID is required. Use the
Spring-hosted URL even if you normally advertise a custom domain.

Spring listings can be physical or digital. Their displayed price is a starting
price you maintain; final prices, shipping, availability, and options are set on
Spring. Local PayPal credentials and the API dropshipping switch are not required
for these links. Spring purchases do not appear in this site's orders, sales
summary, download grants, or fulfilment queue. Mixed browsing is supported, but
Spring items and locally paid products check out separately. Both cart endpoints
and checkout pricing exclude Spring listings, including stale session carts.

The [Spring Seller API documentation](https://api.teespring.com/docs) and its
[published API schema](https://api.teespring.com/swagger_doc/) were checked on
2026-09-13: they do not expose an order-submission endpoint. This integration
therefore uses hosted checkout, without pretending to submit local payments to
Spring. The earlier platform shutdown claim was unverified and has been removed.

### How an order reaches a supplier

```
capture ──> order PAID ──> queue one row per supplier   (no network here, ever)
                                  │
        receipt on screen ────────┤  the browser nudges the queue
        cron: store.fulfil.php ───┤  every few minutes
        admin: Send now ──────────┘  on the order, or on the whole queue
                                  │
                             submit ──> SENT (supplier order id recorded)
                                  │
                             poll ───> SHIPPED (+ tracking number)
```

**Nothing is sent to a supplier during checkout.** The capture path writes a queued
row and stops. A supplier that is slow, rate limited or down therefore cannot make
a completed payment look like a failed one — which is also why the queue, not the
order row, is where a submission's errors and retries live.

Each submission is claimed with a conditional update before it is sent, so a cron
run and an admin pressing **Send now** at the same moment cannot send the same
order twice, and the queue is keyed on (order, supplier) so a replayed capture
cannot become a second supplier order.

A supplier's answer decides what happens next: a rejection (a discontinued variant,
a missing state code — anything in the 4xx range except rate limiting) stops that
row at **failed** with the reason shown on the order, because retrying it unchanged
would fail identically. An outage, a 5xx or a rate limit puts it back on the queue
for the next run.

### Keeping it moving

Run the drain from cron — a line every five minutes calling:

```
php /path/to/ghoti/mod/store/store.fulfil.php >/dev/null
```

It submits what is queued and polls what is already with a supplier, which is how
tracking numbers arrive and how an order becomes **shipped** on its own. Without
it, orders still go out when a buyer reaches the receipt or when an admin presses
a button, but nothing is ever collected back. `--submit-only`, `--sync-only`,
`--limit=N` and `--quiet` are available; it exits non-zero when something is left
for another run, so a cron wrapper can alert on it.

### Adding another supplier

Write a class in `mod/store/store.dropship.php` extending `StoreDropshipDriver` with
`submitOrder()`, `fetchStatus()` and `configured()`, then add a row to
`StoreDropship::drivers()` naming its class, its credential fields and what it calls
its ids. The admin screens, the product form, the queue, the retry path and the CLI
are all driven by that registry, so nothing else changes. Throw
`StoreDropshipPermanentException` for "this order is wrong" and
`StoreDropshipException` for "try again later" — that distinction is what the queue
uses to decide between stopping and retrying.

### What dropshipping does not do here

- **No live shipping rates.** Shipping stays the one flat rate; suppliers quote per
  destination. Quoting live would put a third-party call in the cart path.
- **No supplier catalogue sync.** Products, prices and images are yours; only the
  variant ids point at the supplier. Nothing imports a catalogue or tracks stock.
- **No supplier cost tracking.** The order records what the buyer paid, not what
  the supplier charged you.
- **A supplier rejection does not refund anyone.** The order stays paid, the
  submission is marked failed with its reason, and it is yours to resolve.
- **Deleting a product orphans a submission that has not been sent yet.** The
  mapping lives on the product, so a queued row whose product is gone fails with
  "no lines are mapped to that supplier any more" and stops retrying. Nothing is
  lost — the order line keeps its own copy of the name and price — but that order
  has to be placed with the supplier by hand. Ship the open orders before
  clearing out the catalogue.
- **Anyone can nudge the queue.** The endpoint the receipt page calls to submit
  queued rows takes no arguments and acts only on rows this site queued, so the
  worst a stranger can do is make submissions that were going to happen fire a
  little sooner.

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
| `mod/store/store.dropship.php` | Supplier drivers (Printful, Printify, CJ, webhook) behind one interface |
| `mod/store/store.fulfil.php` | CLI drain for the supplier queue, for cron |
| `mod/store/store.async.php` | Endpoints, `[store:…]` shortcode, `class storeui` |
| `mod/store/store.download.php` | Token-authorised delivery of a purchased file |
| `mod/store/store.js` / `store.css` | Storefront, checkout, PayPal buttons, admin forms |
| `mod/store/store.sql`, `insert.sql` | Schema and the seed settings row |

## Validation

- `php tests/store-product-db.php`: verifies SQL parameter binding and field
  persistence for new and edited products using a query spy.
- `php tests/store-catalog.php`: URL validation, Spring save/update behaviour,
  local cart exclusion, merchandising, escaping, duplicate shortcode IDs, and
  supplier choices in an empty catalogue (also runs the existing store suite).
- `php tests/store-preview.php > /tmp/store-preview.html`: isolated visual fixture
  with no real products or payment calls. Add `--test` to embed
  `tests/store-browser.js`; loading that fixture runs browser assertions for
  filtering, sorting, editor routes, duplication, cart feedback, and overflow.
  Results appear in the body's `data-test-result` attribute.


`php tests/store-dropship.php` covers each supplier driver: the exact request every
one builds, the fields they need, how their statuses and tracking come back, and
that a rejection, an outage and a rate limit are told apart. `php tests/store.php`
additionally covers the queue — that capture queues without calling out, that a
replayed capture does not queue twice, that only the supplier's own lines are sent,
that a drain cannot send the same row twice, and that tracking moves the order to
shipped. It also covers price parsing, cart totalling (including that
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

None of the supplier integrations have been run against a real account: they are
written to each supplier's documented request shape and tested against mock
transports. Place one sandbox or low-value order per supplier you enable and watch
`ghoti.log` before trusting it with real orders.
