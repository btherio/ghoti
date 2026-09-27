# Store

An optional module that sells physical goods, downloads, and digital services and
takes one-time payment through PayPal, Stripe, Square, or cryptocurrency via NOWPayments. Services
can be one-time purchases or recurring PayPal subscriptions. Off by default:
enable it under **Site Settings → Optional modules**, then configure a payment
provider under **Admin Menu → Store**.

## Setting it up

1. **Enable the module.** Site Settings → Optional modules → *Store*, save, reload.
   The `store`, `store_products`, `store_orders`, `store_order_items`,
   `store_downloads`, and `store_subscriptions` tables are created on first use, the same way every other
   module provisions itself.
2. **Connect PayPal.** In the [PayPal Developer dashboard](https://developer.paypal.com/)
   create a REST app and copy its **client ID** and **secret** into
   **Store → Payments & shipping**. Leave the environment on **Sandbox**.
3. **Set the currency and shipping.** The currency is a three-letter code and must
   be one the PayPal account can accept. Shipping is a single flat rate, charged
   once per order that contains a physical item.
4. **Add products**, then buy one from yourself with a PayPal sandbox account.
5. **Switch to Live** only once a sandbox order has appeared under **Orders** with
   status *Paid*. Live and sandbox credentials are different pairs — switching the
   environment without replacing both will fail to take payment.

### Stripe and Square card payments

Both card integrations use the provider's browser component, so card numbers,
expiry dates, and security codes never pass through this application.

For Stripe:

1. Create or open a Stripe account and copy a matching test publishable key
   (`pk_test_…`) and secret key (`sk_test_…`).
2. Save them under **Store → Payments & shipping → Stripe** and enable Stripe.
3. Test a purchase before replacing both keys with their matching live pair.

The server creates one [PaymentIntent](https://docs.stripe.com/api/payment_intents/create)
from its own order total. Stripe Elements collects and authenticates the card.
After confirmation, the server retrieves the PaymentIntent and requires status
`succeeded`, the full amount received, the expected currency, and the saved order
reference before fulfilling anything.

For Square:

1. Create a Square application and copy its application ID, location ID, and
   access token from the same Sandbox environment.
2. Save them under **Store → Payments & shipping → Square**, leave the environment
   on **Sandbox**, and enable Square.
3. Test a purchase before changing the environment and all credentials to Live.

Square's [Web Payments SDK](https://developer.squareup.com/docs/web-payments/overview)
tokenizes the card in the browser. The one-time token is sent to this server,
which writes a pending order and calls [CreatePayment](https://developer.squareup.com/reference/square/payments/create-payment)
with an idempotency key, the server-computed amount, currency, location, and order
reference. Only a matching `COMPLETED` payment fulfils the order. Square requires
the checkout to run over HTTPS.

**Content-security policy.** Both card forms load third-party scripts, frames,
styles and fonts, which the site's CSP would otherwise block. `index.php` adds
them automatically, but only for a processor that is enabled and has complete
credentials: Stripe's Stripe.js origins (`js.stripe.com`, `*.js.stripe.com`,
`hooks.stripe.com` for 3-D Secure, `api.stripe.com`), and Square's origins for the
configured environment only (sandbox *or* production `squarecdn`/`pci-connect`
hosts, Square's font hosts, and its SDK error-reporting host). The lists come from
[Stripe's](https://docs.stripe.com/security/guide#content-security-policy) and
[Square's](https://developer.squareup.com/docs/web-payments/content-security-policy)
CSP guidance; they live in `StoreStripeClient::cspOrigins()` and
`StoreSquareClient::cspOrigins()`. If a card form stays blank, check the browser
console for a CSP violation first. A web server that sets its own CSP must add
the same origins. `tests/store-csp.php` checks every SDK host `store.js` loads
is allowed.

Stripe and Square in this version cover one-time orders. Recurring service plans
continue to use PayPal subscriptions. There is no public Stripe or Square webhook
endpoint; an administrator can use **Refresh payment** on a pending order that has
a provider payment ID.

### Bitcoin and other cryptocurrency

Crypto checkout uses [NOWPayments](https://nowpayments.io/), which creates a
deposit address and quote for each order and exposes the authoritative payment
status through its [Payment API](https://documenter.getpostman.com/view/7907941/S1a32n38).

1. Create a NOWPayments account and API key.
2. Under **Store → Payments & shipping → Bitcoin & cryptocurrency**, paste the
   key and enable crypto checkout.
3. Enter the currency codes you want to offer, separated by commas. For example,
   `btc, eth, ltc, usdc`. Only list assets and networks enabled for your provider
   account; network-specific assets may have codes such as `usdttrc20`.
4. Place a small real order and verify that it moves from *Waiting* through
   network confirmation to *Paid* before advertising the option.

The API key stays server-side. Leaving its field blank when saving keeps the
stored key. Like the PayPal secret, it is stored in the database and therefore
also exists in backups. Crypto is offered only for one-time cart orders;
recurring services continue to use PayPal subscriptions.

The payment page shows the exact crypto amount, deposit address, network, and
memo/tag when required. It checks status every 12 seconds while open. A pending
crypto order can also be checked later with **Refresh payment** under **Orders**.
This version has no public NOWPayments webhook endpoint, so keep the payment page
open through confirmation or refresh it from the manager. An order is fulfilled
only when the provider reports a confirmed, sending, or finished payment and its
local reference, fiat amount, fiat currency, and crypto asset all match.

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
| Kind | *Physical* asks for shipping; *digital* delivers a download; *service* is provisioned by an administrator |
| Fulfilled by | *You*, or a supplier — see [Dropshipping](#dropshipping). A supplier-fulfilled item is still physical |
| Category | What `[store:CATEGORY]` selects |
| Image URL | Rendered in an `<img src>`, so it is scheme-checked like every other URL the CMS accepts |
| File to deliver | Digital products only: a path **under `files/store/`** that already exists |
| Featured | Pins the product ahead of other products in the default collection order |
| Original price | Optional higher price shown crossed out beside the selling price; blank clears it |
| Product badge | Optional short label, such as “New arrival” or “Limited edition” |
| Delivery note | Optional product-specific timing or delivery details |
| Billing | Services only: a one-time cart purchase or a recurring PayPal subscription |
| Service / billing term | Customer-facing wording such as `one-time setup`, `per month`, or `per year` |
| Setup question | Optional question for a domain, preferred account name, migration notes, or other provisioning details; it can be required |
| PayPal plan ID | Recurring services only: the `P-…` ID of an active plan in the matching PayPal sandbox or live environment |
| Spring product URL | Shown when Spring / Teespring is selected; links directly to hosted checkout |
| Show in the store | Unticking hides it without deleting it |

Deleting a product does not alter past orders: every line item keeps its own copy
of the name, SKU, kind and price that was actually charged.

## Browsing and merchandising

The responsive collection includes product search (name, description, or SKU),
category chips, physical/download/service/Spring filters, and sorting by featured, newest,
price, or name. Controls are independent when multiple `[store:…]` shortcodes
appear on one page. Product details expand in place; missing images get a styled
placeholder. Colours inherit the selected Ghoti theme across catalogue, cart, checkout and
management. Laptop and phone layouts, keyboard focus, and reduced-motion
preferences are supported. The phone cart rearranges each item into a compact
card instead of requiring horizontal scrolling.

Products can be featured, carry a short badge and delivery note, and show a sale
price beside an optional higher original price. The admin catalogue includes live-product,
service, and Spring counts, thumbnails, and search. **Duplicate** copies product
fields into a hidden draft with a blank SKU, useful for related products or separate
size/colour SKUs. Review the name, price, and supplier mapping before saving it.
This does not introduce automatic variant or inventory synchronization.

Existing installations gain the merchandising and service fields through the
normal additive module schema upgrade. Existing products default to one-time
billing and keep their current kind and fulfilment route.

## Digital services and subscriptions

Choose **Service** for offerings such as email hosting, managed web hosting,
domain setup, maintenance, consulting, or migration work. Services never ask for
a shipping address and cannot be routed to Spring or a dropshipping supplier.
The customer's answer to the setup question is snapshotted with the purchase, so
later edits to the product do not change the provisioning request.

For a one-time service, choose **One-time purchase**. It behaves like any other
local cart item: PayPal captures one payment, the service appears under **Orders**,
and an administrator can mark its separate setup state **Ready** without marking
the order shipped.

For recurring billing:

1. In the PayPal Developer dashboard, create a product and recurring billing
   plan in the same sandbox or live account configured for this store. PayPal's
   [Subscriptions integration guide](https://developer.paypal.com/platforms/subscriptions/integrate/)
   covers the product-and-plan setup.
2. Copy its plan ID (beginning `P-`) into the service product and choose
   **Recurring PayPal subscription**.
3. Keep the catalogue price and billing-term text in sync with the PayPal plan.
   PayPal's approval window is authoritative and shows the actual amount,
   interval, trial, and cancellation terms.
4. New signups appear under **Store → Subscriptions**. Mark the service ready
   after provisioning it. Use **Refresh** to retrieve its current PayPal status
   and next billing date.

A recurring service bypasses the cart because a PayPal subscription approval is
for one plan, not an Orders API cart. After approval the server reads the
subscription directly from PayPal, checks that its ID and plan match the product,
and only accepts `ACTIVE` or `APPROVED` signups. Replayed approval callbacks reuse
the uniquely stored record. Customers manage payment methods and cancellation in
PayPal. This version does not expose a public webhook endpoint, so lifecycle
changes are pulled with **Refresh** rather than pushed automatically.

## Promotions, shipping offers, and loyalty

Open **Store → Promotions & loyalty**. All new rules default to disabled, so an
upgrade does not change your prices or promise rewards automatically.

- **On sale:** set a product's original price above its selling price. The card
  shows the original price, selling price, and rounded-down percentage saving.
  Customers can choose **On sale** in the product filter; the cart also shows
  savings already included in sale prices.
- **Discount codes:** add up to 50 reusable codes. Choose a percentage (1–90%) or
  fixed amount in the store currency, an optional minimum merchandise spend,
  inclusive start/end dates in UTC, and an active switch. Blank dates mean no
  date limit. Remove or disable a code to stop new checkouts using it.
- **Free shipping:** enter a merchandise threshold in the store currency; zero
  disables it. Eligibility uses merchandise **after discounts**, excludes
  shipping itself, and applies to the normal flat shipping charge. Digital-only
  orders still never pay shipping. The cart shows progress toward the threshold.
- **Loyalty points:** set points per currency unit (1–100); zero disables the
  programme. Set a points threshold and member discount (1–50%). Signed-in
  customers earn points on merchandise after discounts, rounded down per order,
  excluding shipping. At the threshold, future purchases qualify for the member
  discount automatically. Points are not spent or redeemable for cash.

Customers enter or clear a code in the cart. The better of a valid code or member
reward applies; they never stack with one another, but both can apply to sale
prices. Minimum spend for a code uses the sale-priced merchandise subtotal before
that code. Discounts leave at least one cent of merchandise payable because this
checkout requires a payment. An invalid, disabled, or expired selected code
must be changed or cleared before starting checkout.

Rewards are attached to the authenticated Ghoti account, never an email typed into
checkout. Guests can buy normally, but earn no points, and guest purchases cannot
be claimed later. Points are counted only from paid/shipped orders, separately
for each currency; cancelled orders stop contributing. Orders placed before this
feature have zero points. Changing the earning rate does not rewrite previously
quoted orders. Disabling loyalty pauses earning and member discounts without
erasing recorded points. Provider refunds are still manual: use **Cancel order /
record external refund** in the order detail to remove its points, then refund in
PayPal or NOWPayments separately. This does not cancel supplier orders or revoke
download grants.

The PayPal request uses its documented [order amount breakdown](https://developer.paypal.com/sdk/orders/v2/definitions/order/)
with a separate discount, leaving line-item prices intact.

Amounts, discount labels, and earned points are snapshotted on each pending order
and shown on receipts (including email) and admin order details. Changing a rule
or removing a code does not reprice an existing pending provider order. Points become
eligible only after verified payment; capture retries do not award them twice.
Code usage is unlimited: there are no single-use or per-customer redemption caps.

Spring purchases remain entirely separate and do not receive local discounts,
shipping offers, or loyalty points. **Save favourite** works for all catalogue
products; **Saved favourites** filters the collection. Favourites stay in this
browser's local storage (up to 500), with an in-memory fallback if storage is
unavailable. They do not sync between devices or accounts.

The normal additive schema upgrade adds `store.commerceConfig`, crypto provider
settings, and the order columns needed for discounts, loyalty, and provider
payment state. No manual data migration is required. Promotions are admin-gated;
cart code changes use the existing CSRF-protected cart RPC. No customer-provided
prices, points, or payment status are trusted.

## How payment works

PayPal checkout follows this flow:

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

Crypto checkout follows the same trust boundary without accepting a browser
claim: the site sends its server-computed fiat total and local reference to
NOWPayments, saves the returned provider id and exact send instructions, then
reads that payment back from the provider. `waiting`, `confirming`, and
`partially_paid` remain pending. Only `confirmed`, `sending`, or `finished` can
advance the local order, and only after amount, fiat currency, crypto asset, and
order reference match the saved snapshot. The conditional pending-to-paid update
makes refreshes and polling idempotent, so they cannot issue a second receipt or
second set of download links.

Stripe follows that same rule with a PaymentIntent: the browser receives only a
publishable key and that order's client secret, Stripe Elements handles the card,
and the server retrieves the intent before accepting `succeeded`. Square's Web
Payments SDK returns a single-use token; the server records the order before
calling Payments API and accepts only a matching `COMPLETED` result. Provider
IDs and statuses are stored on the order so a delayed result can be refreshed.

`paid` is not a status an administrator can set by hand. Only a verified PayPal
capture, Stripe PaymentIntent, Square Payment, or crypto provider status sets it.

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
| `mod/store/store.promotions.php` | Promotion validation and deterministic discount, shipping, and points calculations |
| `mod/store/store.paypal.php` | PayPal Orders v2 client (`StorePaypalClient`), injectable transport |
| `mod/store/store.dropship.php` | Supplier drivers (Printful, Printify, CJ, webhook) behind one interface |
| `mod/store/store.fulfil.php` | CLI drain for the supplier queue, for cron |
| `mod/store/store.async.php` | Endpoints, `[store:…]` shortcode, `class storeui` |
| `mod/store/store.download.php` | Token-authorised delivery of a purchased file |
| `mod/store/store.js` / `store.css` | Storefront, checkout, PayPal buttons, admin forms |
| `mod/store/store.sql`, `insert.sql` | Schema and the seed settings row |

## Validation

- `python tests/store-responsive.py`: Chromium checks all nine themes at 390px
  and 1366px across catalogue, cart, checkout, subscription signup, promotions,
  and store settings;
  settings also run at a constrained 768px width to catch cramped admin grids.
  Requires Chromium on PATH and uses disposable fixtures.
- `php tests/store-promotions.php`: code dates/minimums, fixed/percentage amounts,
  shipping thresholds, loyalty eligibility, checkout snapshots, PayPal discount
  breakdown, paid-only points, replay safety, and admin authorization.
- `tests/store-preview.php` also accepts `--theme=prosimii` (or any theme name)
  and `--view=cart`, `--view=checkout`, or `--view=promotions`. Fixtures never use
  a live database or payment provider. The catalogue's `--test` assertions also
  exercise sale and favourite filters. Browser fixtures expose `data-overflow`
  and `data-surface` for responsive/theme checks.

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

There is no inventory tracking, no tax calculation, no simultaneous
multi-currency, no refunds from the admin screen (refund in PayPal, then cancel
the order here), and no customer order-history area. Loyalty uses existing Ghoti accounts. Shipping is one flat rate, not a
table of zones or weights.

None of the supplier integrations have been run against a real account: they are
written to each supplier's documented request shape and tested against mock
transports. Place one sandbox or low-value order per supplier you enable and watch
`ghoti.log` before trusting it with real orders.
