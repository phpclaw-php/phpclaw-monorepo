PACKAGES = core cloud mcp

# Run tests for all packages
test:
	@for pkg in $(PACKAGES); do \
		echo "\n==> $$pkg"; \
		( cd packages/$$pkg && composer test ) || exit 1; \
	done

# Run tests for a single package: make test-core
test-%:
	cd packages/$* && composer test

# Run static analysis for all packages
analyse:
	@for pkg in $(PACKAGES); do \
		echo "\n==> $$pkg"; \
		( cd packages/$$pkg && composer analyse ) || exit 1; \
	done

# Run static analysis for a single package: make analyse-core
analyse-%:
	cd packages/$* && composer analyse

# Run tests with coverage for all packages
coverage:
	@for pkg in $(PACKAGES); do \
		echo "\n==> $$pkg"; \
		( cd packages/$$pkg && composer test:coverage ) || exit 1; \
	done

# Run tests with coverage for a single package: make coverage-core
coverage-%:
	cd packages/$* && composer test:coverage

# Install dependencies for all packages
install:
	@for pkg in $(PACKAGES); do \
		echo "\n==> $$pkg"; \
		( cd packages/$$pkg && composer install ) || exit 1; \
	done

# Update dependencies for all packages
update:
	@for pkg in $(PACKAGES); do \
		echo "\n==> $$pkg"; \
		( cd packages/$$pkg && composer update ) || exit 1; \
	done

# Run code style check across core, where Pint is configured for the monorepo
format:
	cd packages/core && vendor/bin/pint

.PHONY: test analyse coverage install update format
