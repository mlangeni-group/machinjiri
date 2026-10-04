<?php

namespace Mlangeni\Machinjiri\Testing\Concerns;

use Mlangeni\Machinjiri\Core\Http\HttpRequest;
use Mlangeni\Machinjiri\Core\Http\HttpResponse;
use Mlangeni\Machinjiri\Core\Routing\Router;

trait InteractsWithHttp
{
    protected function get(string $uri, array $headers = []): HttpResponse
    {
        return $this->call('GET', $uri, [], $headers);
    }

    protected function post(string $uri, array $data = [], array $headers = []): HttpResponse
    {
        return $this->call('POST', $uri, $data, $headers);
    }

    protected function put(string $uri, array $data = [], array $headers = []): HttpResponse
    {
        return $this->call('PUT', $uri, $data, $headers);
    }

    protected function delete(string $uri, array $data = [], array $headers = []): HttpResponse
    {
        return $this->call('DELETE', $uri, $data, $headers);
    }

    protected function call(string $method, string $uri, array $data = [], array $headers = []): HttpResponse
    {
        $request = new HttpRequest($method, $uri, $_GET, $data, $_COOKIE, $_SERVER, $headers);
        $router = $this->resolve(Router::class);
        return $router->dispatch($request);
    }

    protected function assertResponseOk(HttpResponse $response): void
    {
        $this->assertEquals(200, $response->getStatusCode(), 'Response status is not 200 OK');
    }

    protected function assertStatus(int $status, HttpResponse $response): void
    {
        $this->assertEquals($status, $response->getStatusCode());
    }

    protected function assertSee(string $text, HttpResponse $response): void
    {
        $this->assertStringContainsString($text, $response->getBody());
    }

    protected function assertSeeText(string $text, HttpResponse $response): void
    {
        $body = strip_tags($response->getBody());
        $this->assertStringContainsString($text, $body);
    }
    
    protected function assertRedirect(string $expectedUri, HttpResponse $response): void
    {
        $this->assertTrue(in_array($response->getStatusCode(), [301, 302, 303, 307, 308]));
        $this->assertEquals($expectedUri, $response->getHeader('Location'));
    }
    
    protected function assertJsonPath(string $path, $expected, HttpResponse $response): void
    {
        $data = json_decode($response->getBody(), true);
        $value = $this->getArrayValueByPath($data, $path);
        $this->assertEquals($expected, $value);
    }
    
    protected function assertExactJson(array $expected, HttpResponse $response): void
    {
        $this->assertJsonStringEqualsJsonString(json_encode($expected), $response->getBody());
    }
    
    private function getArrayValueByPath(array $array, string $path)
    {
        $segments = explode('.', $path);
        $current = $array;
        foreach ($segments as $segment) {
            if (!isset($current[$segment])) {
                return null;
            }
            $current = $current[$segment];
        }
        return $current;
    }
}