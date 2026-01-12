<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use OpenApi\Generator;

class GenerateSwagger extends Command
{
    protected $signature = 'swagger:generate';
    protected $description = 'Generate Swagger (OpenAPI) JSON documentation';

    public function handle()
    {
        $paths = [
            app_path('Http/Controllers/Api'),
            app_path('Swagger'),
            app_path('Models'),
        ];

        echo "=== Scanning files ===" . PHP_EOL;

        foreach ($paths as $p) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($p)) as $file) {
                if ($file->isFile() && pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                    echo "CHECKING: " . $file->getPathname() . PHP_EOL;
                }
            }
        }

        $output = storage_path('app/swagger.json');

        try {
            $generator = new Generator();
            $openapi = $generator->generate($paths);

            file_put_contents($output, $openapi->toJson());
            $this->info('Swagger JSON generated → ' . $output);

        } catch (\Throwable $e) {

            echo PHP_EOL . "===== ERROR LOG =====" . PHP_EOL;
            echo "Message: " . $e->getMessage() . PHP_EOL;
            echo "Exception File: " . $e->getFile() . PHP_EOL;
            echo "Exception Line: " . $e->getLine() . PHP_EOL;
            echo "=====================" . PHP_EOL;

            throw $e;
        }
    }
}
