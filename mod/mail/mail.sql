-- mail: single-row configuration table for the mail module's SMTP connection
-- to your local (Arch Linux) mail server. Loaded/created the same way every
-- other module's table is (see ghotidb::loadModuleSql() - it probes/creates
-- a table with the *same name as the module*, so this table is named `mail`
-- even though it only ever holds one settings row). The seed row is inserted
-- by insert.sql the first time this table is created.
create table if not exists mail(
	`id` int(11) not null,
	`smtpHost` varchar(255) not null default '127.0.0.1',
	`smtpPort` int(11) not null default 25,
	`encryption` varchar(10) not null default 'none',   -- 'none' | 'tls' (STARTTLS) | 'ssl' (implicit TLS)
	`smtpUsername` varchar(255) not null default '',
	`smtpPassword` varchar(255) not null default '',    -- see mail.db.php for why this is stored as-is
	`fromAddress` varchar(255) not null default '',
	`fromName` varchar(120) not null default '',
	`enabled` int(1) not null default 0,
	`updatedAt` int(11) not null default 0,
  PRIMARY KEY  (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 ;
