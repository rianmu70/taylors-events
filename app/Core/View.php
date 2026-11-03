<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Renders PHP templates from app/Views.
 *
 *     View::render('pages/home', ['events' => $events]);
 *
 * The page is rendered first, then placed inside the layout as $content.
 * Templates must print user data with e(): <?= e($event['title']) ?>
 */
final class View
{
    public static function render(string $template, array $data = [], ?string $layout = 'layouts/main'): string
    {
        $content = self::renderFile($template, $data);
        if ($layout === null) {
            return $content;
        }
        return self::renderFile($layout, array_merge($data, ['content' => $content]));
    }

    /** Renders a template on its own, without the layout. Used for partials. */
    public static function partial(string $template, array $data = []): string
    {
        return self::renderFile($template, $data);
    }

    private static function renderFile(string $template, array $data): string
    {
        $file = self::resolve($template);
        $vars = array_filter(
            $data,
            static fn ($key): bool => is_string($key) && $key !== 'this'
                && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) === 1,
            ARRAY_FILTER_USE_KEY,
        );

        $level = ob_get_level();
        ob_start();
        try {
            (static function (string $__file, array $__vars): void {
                extract($__vars, EXTR_SKIP);
                require $__file;
            })($file, $vars);
            return (string) ob_get_clean();
        } catch (Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }
    }

    private static function resolve(string $template): string
    {
        // Only letters, digits, "_", "-" and "/" between names: no "..", no absolute paths.
        if (preg_match('#^[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*$#', $template) !== 1) {
            throw new InvalidArgumentException("Invalid view name: $template");
        }
        $file = BASE_PATH . '/app/Views/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("View not found: app/Views/$template.php");
        }
        return $file;
    }
}
