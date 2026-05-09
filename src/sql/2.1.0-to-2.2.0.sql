CREATE TABLE `guardianships` (
  `guardian_userid` int(11) NOT NULL,
  `child_userid` int(11) NOT NULL,
  `relationship_type` varchar(50) NOT NULL default 'parent',
  PRIMARY KEY (`guardian_userid`,`child_userid`),
  FOREIGN KEY (`guardian_userid`) REFERENCES `users`(`userid`) ON DELETE CASCADE,
  FOREIGN KEY (`child_userid`) REFERENCES `users`(`userid`) ON DELETE CASCADE
);