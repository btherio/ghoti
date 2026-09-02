CREATE TABLE IF NOT EXISTS `users` (
  `userId` int(11) NOT NULL auto_increment,
  `userName` varchar(255) NOT NULL,
  `email` varchar(255) default NULL,
  `password` varchar(255) NOT NULL,
  `admin` int(1) NOT NULL,
  PRIMARY KEY  (`userId`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1 ;

-- password_resets: one-time, short-lived tokens for the mail-based password
-- recovery flow (see login.async.php requestPasswordReset()/resetPassword()
-- and password-reset.php). Only a SHA-256 hash of the token is stored - the
-- token itself only ever exists in the emailed link and the requester's
-- browser, same principle as a session id or an API key: a stolen database
-- dump must not be enough to reset an account.
CREATE TABLE IF NOT EXISTS `password_resets` (
  `resetId` int(11) NOT NULL auto_increment,
  `userId` int(11) NOT NULL,
  `tokenHash` char(64) NOT NULL,
  `createdAt` int(11) NOT NULL default 0,
  `expiresAt` int(11) NOT NULL default 0,
  `usedAt` int(11) default NULL,
  `requestIp` varchar(45) NOT NULL default '',
  PRIMARY KEY  (`resetId`),
  UNIQUE KEY `uq_password_resets_token` (`tokenHash`),
  KEY `idx_password_resets_user` (`userId`),
  KEY `idx_password_resets_expires` (`expiresAt`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1 ;
