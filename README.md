redis-job-queue
===============

RJQ is a job queue manager &amp; worker based on Redis and PHP

# Install as a daemon

1. Checkout :

  mkdir cd /etc/rjq
  cd /etc/rjq
  git checkout https://github.com/ichiriac/redis-job-queue.git ./

2. Install it on your system :

  ln -s /etc/rjq/bin/rjq.php /etc/init.d/rjq
  ln -s /etc/rjq/bin/rjq.php /usr/local/bin/rjq
  sudo update-rc.d rjq defaults

# How to ?

Start to work :
  rjq --start

Stop to work :
  rjq --stop

Restart (after any configuration change)
  rjq --restart

Get some stats :
  rjq --status