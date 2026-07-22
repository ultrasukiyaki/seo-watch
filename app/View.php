<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

final class View
{
    public static function render(string $template, array $data = []): void
    {
        $viewPath = dirname(__DIR__) . '/views/' . $template . '.php';
        if (!is_file($viewPath)) {
            throw new \RuntimeException('ビューが見つかりません: ' . $template);
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $viewPath;
        $content = ob_get_clean();
        require dirname(__DIR__) . '/views/layout.php';
    }

    public static function partial(string $template, array $data = []): string
    {
        $viewPath = dirname(__DIR__) . '/views/' . $template . '.php';
        if (!is_file($viewPath)) {
            throw new \RuntimeException('ビューが見つかりません: ' . $template);
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $viewPath;
        return (string)ob_get_clean();
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
