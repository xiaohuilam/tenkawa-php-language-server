<?php declare(strict_types=1);

namespace Tsufeki\Tenkawa\Index\Storage;

use Tsufeki\Tenkawa\Uri;

class IndexEntry
{
    /**
     * @var Uri
     */
    public $sourceUri;

    /**
     * @var string
     */
    public $category;

    /**
     * @var string
     */
    public $key;

    /**
     * @var mixed
     */
    public $data;
}
