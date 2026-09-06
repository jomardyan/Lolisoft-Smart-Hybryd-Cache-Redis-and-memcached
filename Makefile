PLUGIN_SLUG := smart-hybrid-cache
PLUGIN_DIR := $(PLUGIN_SLUG)
BUILD_DIR := build
PLUGIN_FILE := $(PLUGIN_DIR)/smart-hybrid-cache.php
README_FILE := $(PLUGIN_DIR)/readme.txt
BUMP_SCRIPT := tools/bump-plugin-version.php
ZIP := $(BUILD_DIR)/$(PLUGIN_SLUG).zip
VERSION ?= $(shell sed -nE "s/^ \* Version:[[:space:]]*([^[:space:]]+).*$$/\1/p" $(PLUGIN_FILE) | head -n1)
VERSIONED_ZIP := $(BUILD_DIR)/$(PLUGIN_SLUG)-$(VERSION).zip
PHP_FILES := $(shell find $(PLUGIN_DIR) tests tools -name '*.php' -type f | sort)

.PHONY: test validate all check-deps lint build build-versioned release clean tree version set-version help ci

all: build

help:
	@echo ""
	@echo "Smart Hybrid Cache — Build & Release Targets"
	@echo "============================================="
	@echo ""
	@echo "  make help                 Show this help"
	@echo "  make version              Print detected plugin version"
	@echo "  make lint                 Validate PHP syntax for plugin files"
	@echo "  make build                Build installable ZIP at $(ZIP)"
	@echo "  make build-versioned      Build versioned ZIP at $(VERSIONED_ZIP)"
	@echo "  make set-version VERSION=x.y.z  Update plugin metadata version"
	@echo "  make release              Build both standard and versioned ZIPs"
	@echo "  make ci                   Run lint + build (CI pipeline target)"
	@echo "  make clean                Remove build artifacts"
	@echo "  make tree                 Print plugin file tree"
	@echo ""

check-deps:
	@command -v php >/dev/null 2>&1 || { echo "Error: php is required."; exit 1; }
	@command -v zip >/dev/null 2>&1 || { echo "Error: zip is required."; exit 1; }
	@test -f $(PLUGIN_FILE) || { echo "Error: plugin file not found: $(PLUGIN_FILE)"; exit 1; }
	@test -f $(README_FILE) || { echo "Error: readme file not found: $(README_FILE)"; exit 1; }
	@test -n "$(VERSION)" || { echo "Error: could not detect plugin version from $(PLUGIN_FILE)"; exit 1; }

version:
	@echo $(VERSION)

lint: check-deps
	@echo "Linting PHP files..."
	@for file in $(PHP_FILES); do \
		php -l $$file || exit 1; \
	done
	@echo "PHP lint passed."

set-version:
	@test -n "$(VERSION)" || { echo "Usage: make set-version VERSION=x.y.z"; exit 1; }
	@php $(BUMP_SCRIPT) "$(VERSION)"
	@echo "Version updated to $(VERSION)"

test:
	@php tests/run.php
	@SHC_TEST_ENGINE=redis php tests/run.php
	@SHC_TEST_ENGINE=memcached php tests/run.php

validate:
	@php tools/validate-package.php

build: check-deps lint validate
	@mkdir -p $(BUILD_DIR)
	@echo "Building $(ZIP) (v$(VERSION))"
	@rm -f $(ZIP)
	@zip -qr $(ZIP) $(PLUGIN_DIR) -x '*/.DS_Store'
	@php tools/validate-package.php $(ZIP)
	@echo "Built $(ZIP)"

build-versioned: build
	@cp $(ZIP) $(VERSIONED_ZIP)
	@echo "Built $(VERSIONED_ZIP)"

release: build build-versioned
	@echo ""
	@echo "Release artifacts ready (v$(VERSION)):"
	@echo "  • $(ZIP)"
	@echo "  • $(VERSIONED_ZIP)"

ci: lint test build
	@echo "CI build complete."

clean:
	@rm -rf $(BUILD_DIR)
	@echo "Cleaned $(BUILD_DIR)"

tree:
	@find $(PLUGIN_DIR) -maxdepth 3 -type f | sort
