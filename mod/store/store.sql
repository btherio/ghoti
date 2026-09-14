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
	`currency` varchar(3) not null default 'CAD',
	`shippingCents` int(11) not null default 0,        -- flat rate per order with a physical item
	`shippingNote` varchar(255) not null default '',
	`downloadHours` int(11) not null default 72,       -- how long a download link stays valid
	`downloadLimit` int(11) not null default 5,        -- downloads allowed per purchased item
	`updatedAt` int(11) not null default 0,
  PRIMARY KEY  (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 ;

-- Catalogue. `kind` decides whether checkout asks for a shipping address
-- ('physical') or issues a download token after payment ('digital').
create table if not exists store_products(
	`productId` int(11) not null auto_increment,
	`sku` varchar(60) not null,
	`name` varchar(120) not null,
	`description` varchar(2000) not null default '',
	`priceCents` int(11) not null default 0,
	`kind` varchar(10) not null default 'physical',    -- 'physical' | 'digital'
	`category` varchar(40) not null default 'default', -- selects what [store:CATEGORY] shows
	`imageUrl` varchar(2048) not null default '',
	`downloadPath` varchar(255) not null default '',   -- relative to files/store/ (web-denied), digital only
	`active` int(1) not null default 1,
	`sortOrder` int(11) not null default 0,
	`createdAt` int(11) not null default 0,
  PRIMARY KEY  (`productId`),
  UNIQUE KEY `uq_store_sku` (`sku`),
  KEY `idx_store_category` (`category`,`active`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1 ;

-- Orders. A row is written BEFORE PayPal is asked to capture, so a capture that
-- succeeds while this process dies leaves something to reconcile rather than a
-- charged customer with no record. The unique key on paypalOrderId is what makes
-- a replayed capture impossible to turn into two paid orders.
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
	`paypalOrderId` varchar(64) not null default '',
	`paypalCaptureId` varchar(64) not null default '',
	`payerEmail` varchar(190) not null default '',
	`note` varchar(500) not null default '',
	`createdAt` int(11) not null default 0,
	`paidAt` int(11) not null default 0,
	`shippedAt` int(11) not null default 0,
  PRIMARY KEY  (`orderId`),
  UNIQUE KEY `uq_store_reference` (`reference`),
  UNIQUE KEY `uq_store_paypal_order` (`paypalOrderId`),
  KEY `idx_store_status` (`status`,`createdAt`)
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
