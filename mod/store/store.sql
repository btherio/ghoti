-- store: the shop's own tables. Provisioned the same way every other module's
-- are (see ghotidb::loadModuleSql() - it probes/creates a table with the SAME
-- NAME as the module), so the single-row settings table is named `store` even
-- though the catalogue and orders live in the store_* tables beside it.
--
-- Money is stored in minor units (cents) as integers throughout. Floats cannot
-- represent 0.10 exactly, and a rounding drift of one cent between what is
-- charged and what is recorded is a reconciliation problem, not a display bug.
create table if not exists store(
	`id` int(11) not null,
	`paypalClientId` varchar(255) not null default '',
	`paypalSecret` varchar(255) not null default '',   -- see store.db.php for why this is stored as-is
	`paypalEnv` varchar(10) not null default 'sandbox',-- 'sandbox' | 'live'
	`cryptoEnabled` int(1) not null default 0,
	`cryptoApiKey` varchar(255) not null default '',    -- NOWPayments API key; stored as-is like paypalSecret
	`cryptoCurrencies` varchar(500) not null default 'btc,eth,ltc,usdc',
	`stripeEnabled` int(1) not null default 0,
	`stripePublishableKey` varchar(255) not null default '',
	`stripeSecretKey` varchar(255) not null default '',
	`squareEnabled` int(1) not null default 0,
	`squareApplicationId` varchar(255) not null default '',
	`squareLocationId` varchar(100) not null default '',
	`squareAccessToken` varchar(255) not null default '',
	`squareEnv` varchar(10) not null default 'sandbox',
	`currency` varchar(3) not null default 'CAD',
	`shippingCents` int(11) not null default 0,        -- flat rate per order with a physical item
	`shippingNote` varchar(255) not null default '',
	`downloadHours` int(11) not null default 72,       -- how long a download link stays valid
	`downloadLimit` int(11) not null default 5,        -- downloads allowed per purchased item
	`dropshipEnabled` int(1) not null default 0,       -- route dropship products to a supplier
	`dropshipAutoSubmit` int(1) not null default 1,    -- submit as soon as an order is paid
	`dropshipConfig` mediumtext null,                  -- per-provider credentials as JSON; see store.db.php
	`commerceConfig` mediumtext null,                -- promotions and loyalty rules as JSON
	`updatedAt` int(11) not null default 0,
  PRIMARY KEY  (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 ;

-- Catalogue. `kind` decides whether checkout asks for a shipping address
-- ('physical'), issues a download token ('digital'), or collects details for
-- an administrator-provisioned service ('service').
create table if not exists store_products(
	`productId` int(11) not null auto_increment,
	`sku` varchar(60) not null,
	`name` varchar(120) not null,
	`description` varchar(2000) not null default '',
	`priceCents` int(11) not null default 0,
	`kind` varchar(10) not null default 'physical',    -- 'physical' | 'digital' | 'service'
	`category` varchar(40) not null default 'default', -- selects what [store:CATEGORY] shows
	`imageUrl` varchar(2048) not null default '',
	`downloadPath` varchar(255) not null default '',   -- relative to files/store/ (web-denied), digital only
	`active` int(1) not null default 1,
	`sortOrder` int(11) not null default 0,
	`fulfilment` varchar(10) not null default 'self',  -- 'self' | 'dropship' | 'spring'
	`dropProvider` varchar(20) not null default '',    -- printful | printify | cj | webhook
	`dropProductId` varchar(64) not null default '',   -- supplier product id, where the supplier needs one
	`dropVariantId` varchar(64) not null default '',   -- supplier variant id: what is actually ordered
	`externalUrl` varchar(2048) not null default '', -- Spring-hosted product checkout
	`featured` int(1) not null default 0,
	`compareAtCents` int(11) not null default 0,
	`badge` varchar(32) not null default '',
	`deliveryNote` varchar(160) not null default '',
	`serviceTerm` varchar(80) not null default '',      -- e.g. '1 month' or '1 year'; service only
	`servicePrompt` varchar(160) not null default '',   -- setup question shown at checkout; service only
	`serviceRequired` int(1) not null default 0,
	`billingType` varchar(12) not null default 'one_time', -- 'one_time' | 'subscription'; service only
	`paypalPlanId` varchar(80) not null default '',     -- PayPal P-* plan id; recurring service only
	`createdAt` int(11) not null default 0,
  PRIMARY KEY  (`productId`),
  UNIQUE KEY `uq_store_sku` (`sku`),
  KEY `idx_store_category` (`category`,`active`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1 ;

-- Recurring services are created by PayPal's Subscriptions API rather than the
-- Orders API. Local rows keep the customer/setup details needed to provision
-- the service and a cached PayPal lifecycle status. The status can be refreshed
-- from the manager at any time; PayPal remains authoritative for billing.
create table if not exists store_subscriptions(
	`subscriptionId` int(11) not null auto_increment,
	`paypalSubscriptionId` varchar(64) not null,
	`paypalPlanId` varchar(80) not null,
	`productId` int(11) not null,
	`userId` int(11) null default null,
	`sku` varchar(60) not null default '',
	`name` varchar(120) not null default '',
	`priceCents` int(11) not null default 0,
	`currency` varchar(3) not null default 'CAD',
	`serviceTerm` varchar(80) not null default '',
	`customerName` varchar(120) not null default '',
	`email` varchar(190) not null default '',
	`serviceDetails` varchar(500) not null default '',
	`status` varchar(24) not null default 'APPROVAL_PENDING',
	`nextBillingAt` int(11) not null default 0,
	`serviceStatus` varchar(12) not null default 'pending', -- pending | fulfilled
	`serviceFulfilledAt` int(11) not null default 0,
	`createdAt` int(11) not null default 0,
	`updatedAt` int(11) not null default 0,
  PRIMARY KEY (`subscriptionId`),
  UNIQUE KEY `uq_store_paypal_subscription` (`paypalSubscriptionId`),
  KEY `idx_store_subscription_status` (`status`,`createdAt`),
  KEY `idx_store_subscription_product` (`productId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1 ;

-- Orders. A row is written before PayPal capture or before a crypto payment can
-- be accepted as paid, so a successful provider operation always has something
-- local to reconcile. Crypto orders use a deterministic CRYPTO-* placeholder in
-- the legacy unique paypalOrderId column and keep their real provider id below.
create table if not exists store_orders(
	`orderId` int(11) not null auto_increment,
	`reference` varchar(20) not null,                  -- human-quotable, e.g. GH-7F3K2QAC
	`status` varchar(12) not null default 'pending',   -- pending|paid|shipped|cancelled|failed
	`userId` int(11) null default null,                -- set when a signed-in account checked out
	`email` varchar(190) not null default '',
	`customerName` varchar(120) not null default '',
	`address1` varchar(190) not null default '',
	`address2` varchar(190) not null default '',
	`city` varchar(120) not null default '',
	`region` varchar(120) not null default '',
	`postcode` varchar(32) not null default '',
	`country` varchar(2) not null default '',
	`subtotalCents` int(11) not null default 0,
	`shippingCents` int(11) not null default 0,
	`totalCents` int(11) not null default 0,
	`currency` varchar(3) not null default 'CAD',
	`hasPhysical` int(1) not null default 0,
	`hasService` int(1) not null default 0,
	`serviceStatus` varchar(12) not null default '',    -- '' | pending | fulfilled
	`serviceFulfilledAt` int(11) not null default 0,
	`paypalOrderId` varchar(64) not null default '',
	`paypalCaptureId` varchar(64) not null default '',
	`payerEmail` varchar(190) not null default '',
	`note` varchar(500) not null default '',
	`createdAt` int(11) not null default 0,
	`paidAt` int(11) not null default 0,
	`shippedAt` int(11) not null default 0,
	`discountCents` bigint not null default 0,
	`discountLabel` varchar(80) not null default '',
	`loyaltyPoints` bigint not null default 0,
	`paymentProvider` varchar(20) not null default 'paypal', -- paypal | crypto | stripe | square
	`cryptoPaymentId` varchar(80) not null default '',
	`cryptoStatus` varchar(24) not null default '',
	`cryptoCurrency` varchar(24) not null default '',
	`cryptoAmount` varchar(80) not null default '',
	`cryptoAddress` varchar(255) not null default '',
	`cryptoExtraId` varchar(255) not null default '',   -- memo/tag required by some currencies
	`cryptoNetwork` varchar(40) not null default '',
	`cryptoExpiresAt` int(11) not null default 0,
	`cryptoUpdatedAt` int(11) not null default 0,
	`providerPaymentId` varchar(100) not null default '', -- Stripe PaymentIntent or Square Payment id
	`providerStatus` varchar(32) not null default '',
  PRIMARY KEY  (`orderId`),
  UNIQUE KEY `uq_store_reference` (`reference`),
  UNIQUE KEY `uq_store_paypal_order` (`paypalOrderId`),
  KEY `idx_store_status` (`status`,`createdAt`),
  KEY `idx_store_crypto_payment` (`cryptoPaymentId`),
  KEY `idx_store_provider_payment` (`providerPaymentId`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1 ;

-- Line items keep their own copies of the name, price and kind. A later price
-- change or a deleted product must not rewrite what somebody already paid.
create table if not exists store_order_items(
	`itemId` int(11) not null auto_increment,
	`orderId` int(11) not null,
	`productId` int(11) not null,
	`name` varchar(120) not null default '',
	`sku` varchar(60) not null default '',
	`kind` varchar(10) not null default 'physical',
	`unitCents` int(11) not null default 0,
	`quantity` int(11) not null default 1,
	`serviceTerm` varchar(80) not null default '',
	`serviceDetails` varchar(500) not null default '',  -- buyer's checkout answer; service only
  PRIMARY KEY  (`itemId`),
  KEY `idx_store_item_order` (`orderId`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1 ;

-- One row per purchased digital item. The token is the only thing that grants
-- access to the file, so it is random, expiring, and download-counted.
create table if not exists store_downloads(
	`downloadId` int(11) not null auto_increment,
	`token` varchar(64) not null,
	`orderId` int(11) not null,
	`productId` int(11) not null,
	`downloads` int(11) not null default 0,
	`maxDownloads` int(11) not null default 5,
	`expiresAt` int(11) not null default 0,
	`createdAt` int(11) not null default 0,
  PRIMARY KEY  (`downloadId`),
  UNIQUE KEY `uq_store_token` (`token`),
  KEY `idx_store_download_order` (`orderId`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1 ;

-- Fulfilment is a state machine of its own, deliberately not columns on the
-- order: payment is authoritative and must never be held up, or made to look
-- failed, by a supplier being slow or down. A paid order queues a row here and
-- a later pass drains it.
--
-- One row per (order, provider): an order whose lines come from two suppliers
-- becomes two submissions, each tracked and retried on its own. providerOrderId
-- is empty until the supplier accepts it, which is why the unique key is on the
-- pair above it rather than on that column.
create table if not exists store_order_fulfilments(
	`fulfilmentId` int(11) not null auto_increment,
	`orderId` int(11) not null,
	`provider` varchar(20) not null,
	`providerOrderId` varchar(64) not null default '',
	`status` varchar(12) not null default 'queued',    -- queued|sending|sent|shipped|failed|cancelled
	`trackingNumber` varchar(120) not null default '',
	`trackingUrl` varchar(500) not null default '',
	`carrier` varchar(80) not null default '',
	`lastError` varchar(500) not null default '',
	`attempts` int(11) not null default 0,
	`createdAt` int(11) not null default 0,
	`sentAt` int(11) not null default 0,
	`syncedAt` int(11) not null default 0,
  PRIMARY KEY  (`fulfilmentId`),
  UNIQUE KEY `uq_store_fulfilment` (`orderId`,`provider`),
  KEY `idx_store_fulfilment_status` (`status`,`createdAt`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1 ;
