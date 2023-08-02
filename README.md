## wordpress-eazibackup

# About

A wordpress plugin for simple backups. Premium feature: automated off-site encrypted backups.

wordpress-eazibackup is a Wordpress plugin that offers a simple backup
method. You setup a schedule by ticking the days of the week you want a
backup to be performed, the hour to run the backup job and the number of
backup files you want to keep handy.

The plugin will execute the backup on the scheduled dates and store a file
locally within your wordpress, under wp-content/plugins/eaZIbackup/backups.
A list of all available backup files are available under eaZIbackup and can
be downloaded off site. An md5 hash also exists to verify your download.

At a small premium this plugin offers automated off-site encrypted backups
as well. Upon completion of a local backup, the file will be encrypted and
shipped off-site. You have access to the remote files when registering for
this service at https://my-app.gr/eaZIbackup/register.php. (Registrations
are not open for the moment but there are some available seats for testing
the service. Get your free access until registration is open by sending an
email to eazibackup@my-app.gr.)

Disclaimer: This software is provided as-is, without any warranty. You have
to check by yourself if it fits your needs.

# Installation

Until the plugin gets into wordpress.org, you simply have to make a folder
in the plugins of your wordpress and place eaZIbackup.php in it:

```
mkdir wp-content/plugins/eaZIbackup
cp eaZIbackup.php wp-content/plugins/eaZIbackup/
```

The folder wp-content/plugins/eaZIbackup needs to have read-write access by
the system user running the webserver, typically www-data. To do this we
need to run the following:

```
chown www-data:www-data wp-content/plugins/eaZIbackup
```

# Thank you

Thank you for trying out eaZIbackup.

