<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Thayron\CjDropshipping\Laravel\Middleware\VerifyCjWebhookSignature;
use Thayron\CjDropshipping\Webhooks\WebhookEvent;
use Thayron\CjDropshipping\Webhooks\WebhookType;
use Thayron\LunarCjDropshipping\Jobs\SyncProductJob;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Models\VariantLink;
use Thayron\LunarCjDropshipping\Support\CjLog;

final class WebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var WebhookEvent $event */
        $event = $request->attributes->get(VerifyCjWebhookSignature::EVENT_ATTRIBUTE);

        $ttl = now()->addHours((int) config('lunar-cjdropshipping.webhooks.dedupe_ttl_hours', 48));

        if (! Cache::add('lunar-cjdropshipping.webhook.'.sha1($event->messageId), true, $ttl)) {
            return new JsonResponse(['status' => 'duplicate']);
        }

        if (! in_array($event->type, [WebhookType::Product, WebhookType::Variant, WebhookType::Stock], true)) {
            CjLog::channel()->debug('Ignored CJ webhook type', ['type' => $event->typeName, 'message_id' => $event->messageId]);

            return new JsonResponse(['status' => 'ignored']);
        }

        $link = $this->findLink($event->params);

        if ($link === null) {
            return new JsonResponse(['status' => 'ignored']);
        }

        SyncProductJob::dispatch($link);

        return new JsonResponse(['status' => 'queued']);
    }

    /**
     * @param  array<array-key, mixed>  $params
     */
    private function findLink(array $params): ?ProductLink
    {
        foreach (['pid', 'productId'] as $key) {
            if (is_scalar($params[$key] ?? null)) {
                $link = ProductLink::query()->whereHas('product')->where('cj_product_id', (string) $params[$key])->first();

                if ($link !== null) {
                    return $link;
                }
            }
        }

        foreach (['vid', 'variantId'] as $key) {
            if (is_scalar($params[$key] ?? null)) {
                $variantLink = VariantLink::query()->whereHas('productLink.product')->where('cj_variant_id', (string) $params[$key])->first();

                if ($variantLink !== null) {
                    return $variantLink->productLink;
                }
            }
        }

        return null;
    }
}
