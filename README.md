# mysql4redis

Note: Testes on Mysql 8 and Mysql 9.

The mysql udf for redis.

I found this [mysql2redis](https://github.com/wurei/mysql4redis.git/) which already removed dependencies [apr](http://apr.apache.org/download.cgi), but it still have some problem and cannot compile with MySQL 5.6 so I rewrite it.

hiredis:https://github.com/redis/hiredis

In Debian, maybe you have to install below library:

apt-get install -y intltool libtool m4 automake build-essential libmysqlclient-dev libssl-dev


./autogen.sh

./configure --prefix=/opt/

make && make install

