conn=new Mongo();
db=conn.getDB('admin');
db.createUser(
  {
    user: 'YOUR_ADMIN_USER',
    pwd: passwordPrompt(),
    roles:[{role:'root',db:'admin'}]
  }
);
db=conn.getDB('wwwdrv');
db.createUser(
  {
    user: 'drvuser',
    pwd: passwordPrompt(),
    roles:[{role:'readWrite',db:'wwwdrv'}]
  }
);

// Indexes the application expects (added by this review - the install guide used to
// tell you to create the first one by hand).
db.cache_gd.createIndex({userId:1,version:1,urlHash:1})
db.accessTokens.createIndex({userId:1,service:1})
// Backs the site-access-code lockout ($authCodeMaxFails); the TTL clears a
// lockout an hour after the last failed attempt.
db.authfail.createIndex({ts:1},{expireAfterSeconds:3600})
