FROM mariadb:12-noble

COPY src/sql/create-phpgiftregdb.sql /docker-entrypoint-initdb.d/create-phpgiftregdb.sql

RUN chmod 0444 /docker-entrypoint-initdb.d/create-phpgiftregdb.sql
