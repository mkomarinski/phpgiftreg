FROM mysql:8.0

COPY src/sql/create-phpgiftregdb.sql /docker-entrypoint-initdb.d/create-phpgiftregdb.sql

RUN chmod 0444 /docker-entrypoint-initdb.d/create-phpgiftregdb.sql
