#!/bin/sh
# Runs once, when the PostgreSQL data volume is first initialised.
# Creates the isolated database used by the automated test-suite.
set -eu

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
    CREATE DATABASE ${DB_TEST_DATABASE:-medconnect_test} OWNER ${POSTGRES_USER};
EOSQL
