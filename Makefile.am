ACLOCAL_AMFLAGS = -I osm2pgsql/m4
AUTOMAKE_OPTIONS = -Wno-portability

SUBDIRS = osm2pgsql modules/transliterate/plain nominatim

NOMINATIM_SERVER ?= $(shell echo a | php -F lib/init.php -E 'echo CONST_Website_BaseURL."\n";')

install:
	@echo Nominatim needs to be executed directly from this directory. No install necessary.

test:
	cd tests; NOMINATIM_SERVER=${NOMINATIM_SERVER} lettuce -t -Fail -t -poldi-only

test-db:
	cd tests; NOMINATIM_SERVER=${NOMINATIM_SERVER} lettuce -t -Fail -t -poldi-only features/db features/osm2pgsql

test-api:
	cd tests; NOMINATIM_SERVER=${NOMINATIM_SERVER} lettuce -t -Fail -t -poldi-only features/api

site-install: SITE ?= site

site-install:
	$(if $(M),,$(error No module to install given (with option M=...)))
	@make -C $(M) SITE=$(abspath $(SITE)) site-install


.PHONY: test test-db test-api site-install
