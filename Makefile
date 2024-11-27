TIMESTAMP := $(shell date '+%Y-%m-%d %H:%M:%S')

.PHONY: setup
setup:
	npm install

.PHONY: dev
dev: setup
	npx wp-env start
	npx wp-env run cli wp import /var/www/html/wp-content/plugins/woocommerce/sample-data/sample_products.xml --authors=create

.PHONY: down
down:
	npx wp-env down

.PHONY: clean
clean:
	npx wp-env clean all
	rm -rf ./node_modules
	rm -rf ./vendor
	rm -f ./package-lock.json

.PHONY:package
package:
	rm -rf tmp
	mkdir tmp
	cp -R updates tmp
	cp admetrics.php tmp
	cp admetrics-data-studio-integration.php tmp
	cp changelog.txt tmp
	cp README.md tmp
	rm -f admetrics-data-studio.zip
	cd tmp; zip -r ../admetrics-data-studio.zip ./*; cd ..
	rm -rf tmp

.PHONY: release
release:
	@if echo "$(VERSION)" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+$$'; then \
  		if [ -z "$(shell git status --porcelain)" ]; then \
			echo "Adjusting file contents for new version"; \
			sed -i '' -E 's/"last_updated": "[0-9]+-[0-9]+-[0-9]+ [0-9]+:[0-9]+:[0-9]+"/"last_updated": "$(TIMESTAMP)"/' ./updates/info.json; \
			sed -i '' -E 's/"version": "[0-9]+\.[0-9]+\.[0-9]+"/"version": "$(VERSION)"/' ./updates/info.json; \
			sed -i '' -E 's/[0-9]+\.[0-9]+\.[0-9]+\.zip/$(VERSION).zip/' ./updates/info.json; \
			sed -i '' -E 's/\* Version:           ([0-9]+\.[0-9]+\.[0-9]+)/* Version:           $(VERSION)/' ./admetrics.php; \
			sed -i '' -E 's/const VERSION = "([0-9]+\.[0-9]+\.[0-9]+)"/const VERSION = "$(VERSION)"/' ./admetrics.php; \
			echo "Committing changes"; \
			git commit -am 'Release $(VERSION)'; \
			echo "Creating package"; \
			make package; \
			echo "All done. Please create a new release for $(VERSION) on https://github.com/admetrics-io/woocommerce-plugin/releases/ and upload admetrics-data-studio.zip as binary to it."; \
		else \
        	echo "Working directory not clean"; \
		fi \
    else \
        echo "Missing or invalid VERSION"; \
    fi
