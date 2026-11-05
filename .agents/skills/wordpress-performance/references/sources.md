# Sources

The claims in this skill were verified against:

- WordPress 7.1.4 source;
- php-src 8.5;
- Composer 2.11;
- New Relic PHP agent 12.12;
- pinned Automattic repositories;
- VIP documentation;
- a lab that ran PHP 8.4, 8.5 and 8.6 against WordPress 7.1.4's real hook API.

Re-check a claim here before relying on it for a different version.

## WordPress core

- WP_Hook (dispatch through `call_user_func_array`): https://github.com/WordPress/wordpress-develop/blob/7.1.4/src/wp-includes/class-wp-hook.php
- plugin.php (`add_filter`, `apply_filters` counters, `_wp_filter_build_unique_id`): https://github.com/WordPress/wordpress-develop/blob/7.1.4/src/wp-includes/plugin.php
- Boot order: https://github.com/WordPress/wordpress-develop/blob/7.1.4/src/wp-settings.php
- Multisite switching: https://github.com/WordPress/wordpress-develop/blob/7.1.4/src/wp-includes/ms-blogs.php
- Early request classification: https://github.com/inpsyde/wp-context
- Core hook metadata: https://github.com/wp-hooks/wordpress-core

## PHP engine

- Lazy objects: https://wiki.php.net/rfc/lazy-objects
- Preloading and per-request OPcache costs: https://wiki.php.net/rfc/preload
- Inheritance cache: https://github.com/php/php-src/commit/4b79dba93202ed5640dff317046ce2fdd42e1d82
- FPM request accounting (ends before shutdown functions): https://github.com/php/php-src/blob/php-8.5.12/sapi/fpm/fpm/fpm_request.c
- Composer prepends its loader: https://github.com/composer/composer/blob/2.11.1/src/Composer/Autoload/AutoloadGenerator.php

## Observability

- New Relic WordPress instrumentation (callback resolution at `add_filter`): https://github.com/newrelic/newrelic-php-agent/blob/v12.12.0.41/agent/fw_wordpress.c
- New Relic PHP configuration (`newrelic.framework.wordpress.hooks.*`): https://docs.newrelic.com/docs/apm/agents/php-agent/configuration/php-agent-configuration/
- New Relic custom attributes: https://docs.newrelic.com/docs/apm/agents/php-agent/php-agent-api/newrelic_add_custom_parameter/
- Query Monitor hooks collector: https://github.com/johnbillion/query-monitor/blob/4.1.1/collectors/hooks.php
- wp profile: https://github.com/wp-cli/profile-command
- Xdebug traces: https://xdebug.org/docs/trace

## WordPress VIP

- Cron Control: https://docs.wpvip.com/wordpress-on-vip/cron-control/
- Runner, one command per event: https://github.com/Automattic/cron-control-runner/blob/933373fbf19ffc3da0d08fefb053aafed8a8f1f4/performer/cli.go
- Action Scheduler on VIP: https://github.com/Automattic/vip-go-mu-plugins/blob/eaa9de6750edc44eda6d93edf06b54496c12b945/cron/action-scheduler-dynamic-queue.php
- PHP on VIP (extensions, memory): https://docs.wpvip.com/wordpress-on-vip/php/
- New Relic on VIP: https://docs.wpvip.com/performance/new-relic/for-wordpress/ and https://docs.wpvip.com/performance/new-relic/limitations/
- VIP APM: https://docs.wpvip.com/performance/vip-application-performance-monitoring/
- `vip-config.php`: https://docs.wpvip.com/wordpress-skeleton/vip-config-directory/
- Page-cache bypass cookies: https://docs.wpvip.com/caching/page-cache/cookies/

## Containers and compiled wiring

- Laravel container and events dispatcher: https://github.com/laravel/framework/tree/13.x/src/Illuminate
- Symfony lazy listeners compiler pass: https://github.com/symfony/symfony/blob/8.1/src/Symfony/Component/EventDispatcher/DependencyInjection/RegisterListenersPass.php
- Symfony lazy commands: https://symfony.com/doc/current/console/lazy_commands.html
- lucatume/di52 `callback()`: https://github.com/lucatume/di52
- Yoast SEO loader and conditionals: https://github.com/Yoast/wordpress-seo/blob/trunk/src/loader.php
- WooCommerce container change: https://developer.woocommerce.com/2024/11/15/developer-advisory-changes-to-the-dependency-injection-container-in-woocommerce/
- Compiled attribute hooks for WordPress: https://github.com/spenserhale/wp-autohooks-library
