<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Error\ErrorCode;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/** Keeps api/openapi.yaml equal to what the application really serves. */
final class OpenApiTest extends TestCase
{
    private string $document;

    protected function setUp(): void
    {
        parent::setUp();
        $path = base_path('api/openapi.yaml');
        self::assertFileExists($path);
        $this->document = str_replace("\r\n", "\n", (string) file_get_contents($path));
    }

    /**
     * "METHOD /path" of every operation in the document. A line scan is enough for the layout this file uses
     * (paths indented two spaces, methods four) and avoids a YAML dependency.
     *
     * @return list<string>
     */
    private function documentedOperations(): array
    {
        $operations = [];
        $current = '';
        $inPaths = false;
        foreach (explode("\n", $this->document) as $line) {
            if ($line === 'paths:') {
                $inPaths = true;
            } elseif ($inPaths && $line !== '' && !str_starts_with($line, ' ')) {
                $inPaths = false;
            } elseif ($inPaths && preg_match('/^  (\/[^:\s]*):\s*$/', $line, $m) === 1) {
                $current = $m[1];
            } elseif ($inPaths && preg_match('/^    (get|post|put|patch|delete):\s*$/', $line, $m) === 1) {
                $operations[] = strtoupper($m[1]) . ' ' . $current;
            }
        }

        return $operations;
    }

    /** @return list<string> */
    private function registeredOperations(): array
    {
        $operations = [];
        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            \assert($route instanceof Route);
            // the document names the item parameter in snake_case like every other field of the API
            $uri = str_replace('{itemId}', '{item_id}', '/' . ltrim($route->uri(), '/'));
            foreach ($route->methods() as $method) {
                if (!in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    $operations[] = $method . ' ' . $uri;
                }
            }
        }
        sort($operations);

        return $operations;
    }

    public function test_the_document_describes_exactly_the_registered_routes(): void
    {
        $registered = $this->registeredOperations();
        $documented = $this->documentedOperations();

        self::assertNotSame([], $registered);
        self::assertSame([], array_values(array_diff($registered, $documented)), 'routes missing from api/openapi.yaml');
        self::assertSame([], array_values(array_diff($documented, $registered)), 'operations in api/openapi.yaml with no route');
    }

    public function test_every_error_code_is_documented(): void
    {
        foreach (ErrorCode::cases() as $code) {
            self::assertStringContainsString($code->value, $this->document, "{$code->value} is not documented");
        }
    }
}
