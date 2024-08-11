.PHONY: clean
clean:
	rm -rf ./node_modules

.PHONY: setup
setup: clean
	npm install

.PHONY: dev
dev:
	npx wp-env start
	npx wp-env run cli wp import /var/www/html/wp-content/plugins/woocommerce/sample-data/sample_products.xml --authors=create

.PHONY: down
down:
	npx wp-env down
