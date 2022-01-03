<?php

declare(strict_types=1);

namespace Kreait\Firebase\JWT\Action\FetchGooglePublicKeys;

use Kreait\Firebase\JWT\Action\FetchGooglePublicKeys;
use Kreait\Firebase\JWT\Contract\Expirable;
use Kreait\Firebase\JWT\Contract\Keys;
use Kreait\Firebase\JWT\Error\FetchingGooglePublicKeysFailed;
use Psr\Clock\ClockInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * @internal
 */
final class WithPsr16SimpleCache implements Handler
{
    private Handler $handler;

    private CacheInterface $cache;

    private ClockInterface $clock;

    public function __construct(Handler $handler, CacheInterface $cache, ClockInterface $clock)
    {
        $this->handler = $handler;
        $this->cache = $cache;
        $this->clock = $clock;
    }

    public function handle(FetchGooglePublicKeys $action): Keys
    {
        $now = $this->clock->now();

        $cacheKey = \md5(\get_class($action));

        /** @noinspection PhpUnhandledExceptionInspection */
        /** @var Keys|Expirable|null $keys */
        $keys = $this->cache->get($cacheKey);

        if (($keys instanceof Keys) && (!($keys instanceof Expirable) || !$keys->isExpiredAt($now))) {
            return $keys;
        }

        try {
            $keys = $this->handler->handle($action);
        } catch (FetchingGooglePublicKeysFailed $e) {
            $reason = \sprintf(
                'The inner handler of %s (%s) failed in fetching keys: %s',
                __CLASS__,
                \get_class($this->handler),
                $e->getMessage()
            );

            throw FetchingGooglePublicKeysFailed::because($reason, $e->getCode(), $e);
        }

        $ttl = ($keys instanceof Expirable)
            ? $keys->expiresAt()->getTimestamp() - $now->getTimestamp()
            : $action->getFallbackCacheDuration()->value();

        $this->cache->set($cacheKey, $keys, $ttl);

        return $keys;
    }
}
