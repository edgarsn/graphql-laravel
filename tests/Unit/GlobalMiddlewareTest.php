<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Tests\Unit;

use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Tests\Support\Middlewares\GlobalMiddleware;
use Rebing\GraphQL\Tests\TestCase;

class GlobalMiddlewareTest extends TestCase
{
    public function testConfigGlobalMiddlewareExecuted(): void
    {
        $this->app['config']->set('graphql.resolver_middleware_append', [
            GlobalMiddleware::class,
        ]);
        $result = GraphQL::queryAndReturnResult($this->queries['examples']);
        self::assertSame(['examples' => [['test' => 'Intercepted by GlobalMiddleware']]], $result->data);
    }

    public function testFacadeGlobalMiddlewareExecuted(): void
    {
        GraphQL::appendGlobalResolverMiddleware(GlobalMiddleware::class);
        $result = GraphQL::queryAndReturnResult($this->queries['examples']);
        self::assertSame(['examples' => [['test' => 'Intercepted by GlobalMiddleware']]], $result->data);
    }
}
