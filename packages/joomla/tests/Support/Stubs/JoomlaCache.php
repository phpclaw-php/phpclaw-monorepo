<?php

declare(strict_types=1);

namespace Joomla\CMS\Cache;

class Cache
{
    public static array $lastOptions = [];

    public array $options;

    public array $items = [];

    public bool $failStore = false;

    public function __construct(array $options = [])
    {
        $this->options = $options;
        self::$lastOptions = $options;
    }

    public function get($id, $group = null)
    {
        return $this->items[$group ?? $this->options['defaultgroup']][$id] ?? false;
    }

    public function store($data, $id, $group = null)
    {
        if ($this->failStore) {
            return false;
        }

        $this->items[$group ?? $this->options['defaultgroup']][$id] = $data;

        return true;
    }

    public function remove($id, $group = null)
    {
        $group = $group ?? $this->options['defaultgroup'];
        $existed = isset($this->items[$group][$id]);
        unset($this->items[$group][$id]);

        return $existed;
    }

    public function clean($group = null, $mode = 'group')
    {
        $this->items[$group ?? $this->options['defaultgroup']] = [];

        return true;
    }
}
