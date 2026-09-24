<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Model;

/**
 * X03: one Trident edge this store invalidates — a name, its admin API and
 * the token for it. See {@see Config::getInstances()} for where they come from.
 */
class Instance
{
    /**
     * @param string $name
     * @param string $apiUrl
     * @param string $apiToken Plain text.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $apiUrl,
        public readonly string $apiToken
    ) {
    }
}
