<?php

declare(strict_types=1);

namespace Crawlora\Exception;

/** Raised for 5xx API responses: the API failed to handle a valid request. */
class ServerError extends CrawloraError
{
}
