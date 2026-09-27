-- boards: message boards placed in a page with [board:slug].
--
-- The module table is named `boards` because ghotidb::loadModuleSql() probes a
-- table with the SAME NAME as the module to decide whether this is a fresh
-- install; the rest live in board_* tables beside it.
--
-- A board is a destination, not a filter. `slug` is what [board:slug] names and
-- is UNIQUE, so a tag always resolves to exactly one board - unlike the links
-- module, where two groups can share a slug and a tag renders both.
--
-- `mode` decides how the board reads:
--   'board'    - a list of topics; each topic opens into its own post list.
--   'comments' - one implicit topic, rendered as a flat comment stream. This is
--                what you put at the bottom of a page you want commentable.
-- Both are the same renderer with the thread list turned off, not two features.
create table if not exists boards(
	`boardId` int(11) not null auto_increment,
	`name` varchar(64) not null default '',
	`slug` varchar(64) not null default '',
	`description` varchar(255) not null default '',
	-- 'board' | 'comments'
	`mode` varchar(10) not null default 'board',
	-- who may post: 'users' | 'mods'
	`postPolicy` varchar(10) not null default 'users',
	-- who may read: 'public' | 'users'
	`readPolicy` varchar(10) not null default 'public',
	-- read-only for everyone but moderators
	`locked` int(1) not null default 0,
	`sortOrder` int(11) not null default 0,
	`createdAt` int(11) not null default 0,
  PRIMARY KEY  (`boardId`),
  UNIQUE KEY `uq_boards_slug` (`slug`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1 ;

-- A thread. In 'comments' mode each board has exactly one, created on demand,
-- and its title is never shown.
create table if not exists board_topics(
	`topicId` int(11) not null auto_increment,
	`boardId` int(11) not null default 0,
	`userId` int(11) not null default 0,
	`title` varchar(160) not null default '',
	`locked` int(1) not null default 0,
	`sticky` int(1) not null default 0,
	`createdAt` int(11) not null default 0,
	-- denormalised for ordering only
	`lastPostAt` int(11) not null default 0,
  PRIMARY KEY  (`topicId`),
  KEY `idx_board_topics_board` (`boardId`,`sticky`,`lastPostAt`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1 ;

-- One post. `boardId` is carried alongside `topicId` so per-board moderation
-- and per-user post counts are one indexed read, with no join through topics.
create table if not exists board_posts(
	`postId` int(11) not null auto_increment,
	`boardId` int(11) not null default 0,
	`topicId` int(11) not null default 0,
	`userId` int(11) not null default 0,
	`body` text not null,
	`createdAt` int(11) not null default 0,
	`editedAt` int(11) not null default 0,
  PRIMARY KEY  (`postId`),
  KEY `idx_board_posts_topic` (`topicId`,`postId`),
  KEY `idx_board_posts_user` (`userId`),
  KEY `idx_board_posts_board` (`boardId`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1 ;

-- Who moderates what. Owned by this module rather than a column on the login
-- module's `users` table, because moderation is per board, not per account.
create table if not exists board_moderators(
	`boardId` int(11) not null default 0,
	`userId` int(11) not null default 0,
	`grantedAt` int(11) not null default 0,
  PRIMARY KEY  (`boardId`,`userId`),
  KEY `idx_board_moderators_user` (`userId`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 ;

-- Per-account notification choices. Opt-in: an account with no row here gets
-- no e-mail. Kept in this module (not a users column) so it goes away with the
-- module's data and the login module does not have to know boards exist.
create table if not exists board_notify(
	`userId` int(11) not null default 0,
	-- e-mail me when someone posts in a thread I have posted in
	`replies` int(1) not null default 0,
	`updatedAt` int(11) not null default 0,
  PRIMARY KEY  (`userId`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 ;
