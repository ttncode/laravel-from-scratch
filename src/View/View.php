<?php

namespace Framework\View;

class View
{
    protected string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');
    }

    /**
     * Render a view file with the given data.
     *
     * @param string $view The view name (e.g. 'home.index')
     * @param array $data
     * @return string
     * @throws \RuntimeException
     */
    public function render(string $view, array $data = []): string
    {
        $path = $this->resolvePath($view);

        if (!file_exists($path)) {
            throw new \RuntimeException("View [$view] not found at [{$path}]");
        }

        // Extract the array into variables
        extract($data);

        // Start output buffering
        ob_start();

        // Load the view file
        include $path;

        // Get the buffered content and clean the buffer
        return ob_get_clean();
    }

    protected function resolvePath(string $view): string
    {

        return $this->basePath . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $view) . '.php';
    }
}
