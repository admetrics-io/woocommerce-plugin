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

.PHONY: dev-package
dev-package:
	rm -rf tmp
	mkdir tmp
	cp -R updates tmp
	cp admetrics.php tmp
	cp admetrics-data-studio-integration.php tmp
	cp changelog.txt tmp
	rm -f admetrics-woocommerce-plugin.zip
	cd tmp; zip -r ../admetrics-woocommerce-plugin.zip ./*; cd ..
	rm -rf tmp
