-- vhosts: single-row configuration table for the vhosts module.
--
-- Loaded/created like every other module's table (see ghotidb::loadModuleSql(),
-- which probes/creates a table named after the module), so this table is named
-- `vhosts` even though it holds exactly one settings row (id=1).
--
-- Deliberately NOT a copy of the vhost definitions. Apache's own config files
-- are the source of truth for what vhosts exist - anything mirrored here would
-- go stale the moment someone edits a .conf by hand or certbot rewrites one.
-- This row only stores the module's own operating parameters.
create table if not exists vhosts(
	`id` int(11) not null,
	`helperPath` varchar(255) not null default '/usr/local/sbin/ghoti-vhosts-helper',
	`dropInDir` varchar(255) not null default '/etc/httpd/conf/conf.d',
	`readOnlyConf` varchar(255) not null default '/etc/httpd/conf/extra/httpd-vhosts.conf',
	`docRootBase` varchar(255) not null default '/etc/httpd/docs',
	`logDir` varchar(255) not null default '/var/log/httpd',
	`certbotEmail` varchar(255) not null default '',
	`enabled` int(1) not null default 0,          -- master switch for write operations
	`updatedAt` int(11) not null default 0,
  PRIMARY KEY  (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 ;
