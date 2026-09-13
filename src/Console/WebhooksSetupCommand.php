<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Thayron\CjDropshipping\CjClient;
use Thayron\CjDropshipping\Criteria\WebhookSettings;
use Thayron\LunarCjDropshipping\Models\ProductLink;

final class WebhooksSetupCommand extends Command
{
    protected $signature = 'cj:webhooks:setup';

    protected $description = 'Point CJdropshipping product and stock webhooks to this app and subscribe linked products';

    public function handle(CjClient $cj): int
    {
        $url = rtrim((string) config('app.url'), '/').'/'.ltrim((string) config('lunar-cjdropshipping.webhooks.path'), '/');

        try {
            $settings = WebhookSettings::make()->product($url)->stock($url);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line("Webhook URL: {$url}");

        if (! $cj->webhooks()->configure($settings)) {
            $this->error('CJdropshipping did not accept the webhook settings.');

            return self::FAILURE;
        }

        $productIds = ProductLink::query()->pluck('cj_product_id')->all();

        if ($productIds !== []) {
            $result = $cj->webhooks()->subscribeProducts($productIds);
            $this->info(sprintf('Subscribed %d product(s), %d failed.', count($result->successProductIds), count($result->failedProductIds)));
        }

        return self::SUCCESS;
    }
}
