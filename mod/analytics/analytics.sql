create table if not exists analytics(
	`id` int(11) not null auto_increment,
	`pageId` int(11) default null,
	`pageTitle` varchar(255) default null,
	`sessionId` varchar(64) not null,
	`userId` int(11) default null,
	`ipAddress` varchar(45) not null,
	`userAgent` varchar(255) not null,
	`browser` varchar(32) default null,
	`os` varchar(32) default null,
	`deviceType` varchar(16) default null,
	`referrer` varchar(500) default null,
	`requestUri` varchar(500) default null,
	`isAdminView` tinyint(1) not null default 0,
	`createdAt` datetime not null default current_timestamp,
  PRIMARY KEY (`id`),
  KEY `idx_createdAt` (`createdAt`),
  KEY `idx_pageId` (`pageId`),
  KEY `idx_sessionId` (`sessionId`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;

