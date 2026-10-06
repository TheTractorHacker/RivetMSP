<?php

declare(strict_types=1);

require_once __DIR__ . '/ConformanceSupport.php';

use RivetCore\Webhooks\WebhookSubscriptionsInterface;

if (ConformanceSupport::kitAvailable()) {
    final class WebhookSubscriptionsConformanceTest extends \RivetCore\Testing\WebhookSubscriptionsConformanceTestCase
    {
        protected function subscriptions(): WebhookSubscriptionsInterface
        {
            return new \RivetMSP\Core\Adapter\Webhooks\WebhooksTableSubscriptions(ConformanceSupport::db());
        }

        /** Stored the way the admin page does: secret encrypted with encryptSetting(), events as a comma list that may hold patterns. */
        protected function storeSubscription(string $url, string $secret, array $events, bool $enabled): int
        {
            return (int) ConformanceSupport::db()->execute(
                "INSERT INTO webhooks (webhook_name, webhook_url, webhook_secret, webhook_events, webhook_enabled) VALUES ('conformance', ?, ?, ?, ?)",
                [$url, encryptSetting($secret), implode(', ', $events), $enabled ? 1 : 0]
            )->insertId;
        }

        protected function deleteSubscription(int $webhookId): void
        {
            ConformanceSupport::db()->execute('DELETE FROM webhooks WHERE webhook_id = ?', [$webhookId]);
        }

        /** True: WebhooksTableSubscriptions selects enabled rows and matches '*' / 'ticket.*' in PHP through EventCatalog (matches()). */
        protected function supportsEventPatterns(): bool
        {
            return true;
        }
    }
} else {
    final class WebhookSubscriptionsConformanceTest extends KitMissingTestCase
    {
    }
}
