ACLOCAL_AMFLAGS = -I osm2pgsql/m4
AUTOMAKE_OPTIONS = -Wno-portability

SUBDIRS = osm2pgsql module nominatim

NOMINATIM_SERVER ?= $(shell echo a | php -F lib/init.php -E 'echo CONST_Website_BaseURL."\n";')

install:
	@echo Nominatim needs to be executed directly from this directory. No install necessary.

test:
	cd tests; NOMINATIM_SERVER=${NOMINATIM_SERVER} lettuce -t -Fail -t -poldi-only

test-db:
	cd tests; NOMINATIM_SERVER=${NOMINATIM_SERVER} lettuce -t -Fail -t -poldi-only features/db features/osm2pgsql

test-api:
	cd tests; NOMINATIM_SERVER=${NOMINATIM_SERVER} lettuce -t -Fail -t -poldi-only features/api

.PHONY: test test-db test-api
