.PHONY: help up-symfony8 down-symfony8 release-verify

help:
	@echo "API Studio Bundle demos"
	@echo "  make up-symfony8     Start Symfony 8 FrankenPHP demo"
	@echo "  make down-symfony8   Stop Symfony 8 demo"
	@echo "  make release-verify  Run demo release-check"

up-symfony8:
	$(MAKE) -C symfony8 up

down-symfony8:
	$(MAKE) -C symfony8 down

release-verify:
	$(MAKE) -C symfony8 release-check


# REQ-MAKE-008: update-deps-all (REQ-MAKE-008)
BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/..)
include $(BUNDLE_ROOT)/../.scripts/Makefile.demo-aggregate-update-deps.mk
