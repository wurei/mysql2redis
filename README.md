# mysql4redis
The mysql udf for redis.

I found this [mysql2redis](https://github.com/wurei/mysql4redis.git/) which already removed dependencies [apr](http://apr.apache.org/download.cgi), but it still have some problem and cannot compile with MySQL 5.6 so I rewrite it.

hiredis:https://github.com/redis/hiredis

./autogen.sh

./configure --prefix=/opt/

make && make install
