<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Exceptions;

use RuntimeException;

final class ListingException extends RuntimeException
{
    /**
     * @param  array<string, string>  $replace
     */
    public static function because(string $key, array $replace = []): self
    {
        return new self((string) __("lunar-cjdropshipping::admin.listing.errors.{$key}", $replace));
    }
}
