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
