APP_NAME=BookStack
APP_ENV={{ $mode ?? 'development' }}
APP_KEY=
APP_DEBUG={{ empty($dev) ? 'false' : 'true' }}
APP_URL="{{ $proto }}{{ $uri }}"

LOG_CHANNEL=stack

DB_CONNECTION=mysql
DB_HOST="{{ $dbhost ?? 'localhost' }}"
DB_PORT=3306
DB_DATABASE="{{ $dbname }}"
DB_USERNAME="{{ $dbuser }}"
DB_PASSWORD="{{ $dbpassword }}"

BROADCAST_DRIVER=log
CACHE_DRIVER=file
SESSION_DRIVER=file
SESSION_LIFETIME=120
QUEUE_DRIVER=sync

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_DRIVER=sendmail
MAIL_HOST=localhost
MAIL_PORT=25
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=null

PUSHER_APP_ID=
PUSHER_APP_KEY=
PUSHER_APP_SECRET=
PUSHER_APP_CLUSTER=mt1

MIX_PUSHER_APP_KEY="${PUSHER_APP_KEY}"
MIX_PUSHER_APP_CLUSTER="${PUSHER_APP_CLUSTER}"