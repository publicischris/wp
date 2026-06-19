<?php
namespace KiMaPa_Content_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

class Config
{
    /** @var string */
    private $path;

    /** @var array|null */
    private $data = null;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function all(): array
    {
        if (null !== $this->data) {
            return $this->data;
        }

        $fallback = $this->defaults();
        if (!is_readable($this->path)) {
            $this->data = $fallback;
            return $this->data;
        }

        $raw = file_get_contents($this->path);
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            $this->data = $fallback;
            return $this->data;
        }

        $this->data = array_replace_recursive($fallback, $decoded);
        return $this->data;
    }

    public function get(string $key, $default = null)
    {
        $value = $this->all();
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }

        return $value;
    }

    private function defaults(): array
    {
        return [
            'tone' => ['familiennah', 'warm', 'praktisch', 'vertrauenswürdig'],
            'avoid_phrases' => [],
            'wordpress_checks' => ['min_word_count' => 450, 'featured_image_min_width' => 1200, 'featured_image_min_height' => 800, 'stale_after_days' => 365],
            'content_consistency_checks' => [],
            'scoring' => ['labels' => []],
            'output_formats' => [],
        ];
    }
}
