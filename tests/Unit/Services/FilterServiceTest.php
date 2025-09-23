<?php

declare(strict_types=1);

use Ameax\ApiLogger\Services\FilterService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

beforeEach(function () {
    $this->filterService = new FilterService([
        'filters' => [
            'enabled' => true,
            'include_routes' => [],
            'exclude_routes' => ['health/*', 'debug/*'],
            'include_methods' => [],
            'exclude_methods' => ['OPTIONS', 'HEAD'],
            'include_status_codes' => [],
            'exclude_status_codes' => [304],
            'min_response_time' => 100,
            'always_log_errors' => true,
        ],
    ]);
});

describe('route filtering', function () {
    it('excludes routes matching exclude patterns', function () {
        $request = Request::create('/health/check', 'GET');
        $response = new Response('OK', 200);

        expect($this->filterService->shouldLog($request, $response, 150))->toBeFalse();
    });

    it('includes routes not matching exclude patterns', function () {
        $request = Request::create('/api/users', 'GET');
        $response = new Response('OK', 200);

        expect($this->filterService->shouldLog($request, $response, 150))->toBeTrue();
    });

    it('respects include routes when specified', function () {
        $filterService = new FilterService([
            'filters' => [
                'include_routes' => ['api/*'],
                'exclude_routes' => [],
            ],
        ]);

        $apiRequest = Request::create('/api/users', 'GET');
        $webRequest = Request::create('/web/page', 'GET');
        $response = new Response('OK', 200);

        expect($filterService->shouldLog($apiRequest, $response, 0))->toBeTrue();
        expect($filterService->shouldLog($webRequest, $response, 0))->toBeFalse();
    });

    it('handles wildcard patterns correctly', function () {
        $filterService = new FilterService([
            'filters' => [
                'exclude_routes' => ['admin/**', 'test/*'],
            ],
        ]);

        $adminDeep = Request::create('/admin/users/edit/1', 'GET');
        $adminShallow = Request::create('/admin/dashboard', 'GET');
        $testShallow = Request::create('/test/unit', 'GET');
        $testDeep = Request::create('/test/unit/deep', 'GET');
        $response = new Response('OK', 200);

        expect($filterService->shouldLog($adminDeep, $response, 0))->toBeFalse();
        expect($filterService->shouldLog($adminShallow, $response, 0))->toBeFalse();
        expect($filterService->shouldLog($testShallow, $response, 0))->toBeFalse();
        expect($filterService->shouldLog($testDeep, $response, 0))->toBeTrue();
    });
});

describe('method filtering', function () {
    it('excludes specified HTTP methods', function () {
        $optionsRequest = Request::create('/api/test', 'OPTIONS');
        $headRequest = Request::create('/api/test', 'HEAD');
        $getRequest = Request::create('/api/test', 'GET');
        $response = new Response('OK', 200);

        expect($this->filterService->shouldLog($optionsRequest, $response, 150))->toBeFalse();
        expect($this->filterService->shouldLog($headRequest, $response, 150))->toBeFalse();
        expect($this->filterService->shouldLog($getRequest, $response, 150))->toBeTrue();
    });

    it('respects include methods when specified', function () {
        $filterService = new FilterService([
            'filters' => [
                'include_methods' => ['POST', 'PUT'],
                'exclude_methods' => [],
            ],
        ]);

        $postRequest = Request::create('/api/test', 'POST');
        $getRequest = Request::create('/api/test', 'GET');
        $response = new Response('OK', 200);

        expect($filterService->shouldLog($postRequest, $response, 0))->toBeTrue();
        expect($filterService->shouldLog($getRequest, $response, 0))->toBeFalse();
    });
});

describe('status code filtering', function () {
    it('excludes specified status codes', function () {
        $request = Request::create('/api/test', 'GET');
        $notModified = new Response('', 304);
        $ok = new Response('OK', 200);

        expect($this->filterService->shouldLog($request, $notModified, 150))->toBeFalse();
        expect($this->filterService->shouldLog($request, $ok, 150))->toBeTrue();
    });

    it('respects include status codes when specified', function () {
        $filterService = new FilterService([
            'filters' => [
                'include_status_codes' => [200, 201],
                'exclude_status_codes' => [],
            ],
        ]);

        $request = Request::create('/api/test', 'GET');
        $ok = new Response('OK', 200);
        $created = new Response('Created', 201);
        $notFound = new Response('Not Found', 404);

        expect($filterService->shouldLog($request, $ok, 0))->toBeTrue();
        expect($filterService->shouldLog($request, $created, 0))->toBeTrue();
        expect($filterService->shouldLog($request, $notFound, 0))->toBeFalse();
    });
});

describe('response time filtering', function () {
    it('filters based on minimum response time', function () {
        $request = Request::create('/api/test', 'GET');
        $response = new Response('OK', 200);

        expect($this->filterService->shouldLog($request, $response, 50))->toBeFalse();
        expect($this->filterService->shouldLog($request, $response, 100))->toBeTrue();
        expect($this->filterService->shouldLog($request, $response, 200))->toBeTrue();
    });

    it('logs all requests when min_response_time is 0', function () {
        $filterService = new FilterService([
            'filters' => [
                'min_response_time' => 0,
            ],
        ]);

        $request = Request::create('/api/test', 'GET');
        $response = new Response('OK', 200);

        expect($filterService->shouldLog($request, $response, 0.001))->toBeTrue();
    });
});

describe('custom filters', function () {
    it('applies custom filter callbacks', function () {
        $filterService = new FilterService;

        $filterService->addCustomFilter(function ($request, $response) {
            return $request->header('X-Custom-Header') === 'log-me';
        });

        $withHeader = Request::create('/api/test', 'GET');
        $withHeader->headers->set('X-Custom-Header', 'log-me');

        $withoutHeader = Request::create('/api/test', 'GET');

        $response = new Response('OK', 200);

        expect($filterService->shouldLog($withHeader, $response, 0))->toBeTrue();
        expect($filterService->shouldLog($withoutHeader, $response, 0))->toBeFalse();
    });

    it('stops on first false custom filter', function () {
        $filterService = new FilterService;

        $called = [];

        $filterService->addCustomFilter(function () use (&$called) {
            $called[] = 1;

            return true;
        });

        $filterService->addCustomFilter(function () use (&$called) {
            $called[] = 2;

            return false;
        });

        $filterService->addCustomFilter(function () use (&$called) {
            $called[] = 3;

            return true;
        });

        $result = $filterService->passesCustomFilters(
            Request::create('/test', 'GET'),
            new Response('OK', 200)
        );

        expect($result)->toBeFalse();
        expect($called)->toBe([1, 2]);
    });
});

describe('configuration methods', function () {
    it('can update include routes', function () {
        $filterService = new FilterService;
        $filterService->includeRoutes(['api/*', 'admin/*']);

        $apiRequest = Request::create('/api/users', 'GET');
        $webRequest = Request::create('/web/page', 'GET');
        $response = new Response('OK', 200);

        expect($filterService->shouldLog($apiRequest, $response, 0))->toBeTrue();
        expect($filterService->shouldLog($webRequest, $response, 0))->toBeFalse();
    });

    it('can update exclude routes', function () {
        $filterService = new FilterService;
        $filterService->excludeRoutes(['private/*']);

        $privateRequest = Request::create('/private/data', 'GET');
        $publicRequest = Request::create('/public/data', 'GET');
        $response = new Response('OK', 200);

        expect($filterService->shouldLog($privateRequest, $response, 0))->toBeFalse();
        expect($filterService->shouldLog($publicRequest, $response, 0))->toBeTrue();
    });

    it('can update HTTP methods filters', function () {
        $filterService = new FilterService;
        $filterService->includeMethods(['POST', 'PUT']);
        $filterService->excludeMethods(['DELETE']);

        $postRequest = Request::create('/api/test', 'POST');
        $getRequest = Request::create('/api/test', 'GET');
        $deleteRequest = Request::create('/api/test', 'DELETE');
        $response = new Response('OK', 200);

        expect($filterService->shouldLog($postRequest, $response, 0))->toBeTrue();
        expect($filterService->shouldLog($getRequest, $response, 0))->toBeFalse();
        expect($filterService->shouldLog($deleteRequest, $response, 0))->toBeFalse();
    });

    it('can update status code filters', function () {
        $filterService = new FilterService;
        $filterService->includeStatusCodes([200, 201]);
        $filterService->excludeStatusCodes([304]);

        $request = Request::create('/api/test', 'GET');
        $ok = new Response('OK', 200);
        $created = new Response('Created', 201);
        $notModified = new Response('', 304);
        $notFound = new Response('Not Found', 404);

        expect($filterService->shouldLog($request, $ok, 0))->toBeTrue();
        expect($filterService->shouldLog($request, $created, 0))->toBeTrue();
        expect($filterService->shouldLog($request, $notModified, 0))->toBeFalse();
        expect($filterService->shouldLog($request, $notFound, 0))->toBeFalse();
    });

    it('can update minimum response time', function () {
        $filterService = new FilterService;
        $filterService->setMinResponseTime(500);

        $request = Request::create('/api/test', 'GET');
        $response = new Response('OK', 200);

        expect($filterService->shouldLog($request, $response, 400))->toBeFalse();
        expect($filterService->shouldLog($request, $response, 600))->toBeTrue();
    });
});
