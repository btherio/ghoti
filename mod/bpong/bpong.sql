-- bpong: one settings row. The game itself keeps no state on the server - it is
-- played entirely in the visitor's browser and nothing about a match is stored,
-- so there is no score table here and no endpoint that writes one.
--
-- The table is named `bpong` because loadModuleSql() decides whether a module
-- has ever been installed by probing a table with the module's own name.
create table if not exists bpong(
	`id` int(11) not null,
	`winningScore` int(11) not null default 7,   -- points that end a match
	`paddleHeight` int(11) not null default 5,   -- cells; the ball is one cell
	`cpuSpeed` int(11) not null default 155,     -- CPU paddle cells/sec x10 (15.5 in the original)
	`showControls` int(1) not null default 1,    -- print the key legend under the board
	`updatedAt` int(11) not null default 0,
  PRIMARY KEY  (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 ;
