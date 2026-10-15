.PHONY: install test test-integration lint lint-fix stan audit verify serve worker

install:
	composer install

test:
	vendor/bin/phpunit --testsuite Unit --testsuite Feature

# Needs PostgreSQL and RabbitMQ: docker compose up -d postgres rabbitmq
test-integration:
	DB_URL=postgres://orders:orders@127.0.0.1:5432/orders BROKER_URL=amqp://guest:guest@127.0.0.1:5672/ vendor/bin/phpunit --testsuite Integration

lint:
	vendor/bin/pint --test

lint-fix:
	vendor/bin/pint

stan:
	vendor/bin/phpstan analyse --memory-limit=1G --no-progress

audit:
	composer audit

# The single local gate, same as CI (the integration tests need the services above).
verify: audit lint stan test

serve:
	php artisan serve

worker:
	php artisan orders:work
