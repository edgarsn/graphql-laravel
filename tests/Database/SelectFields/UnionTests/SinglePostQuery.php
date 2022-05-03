<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Tests\Database\SelectFields\UnionTests;

use Closure;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;
use Rebing\GraphQL\Support\SelectFields;
use Rebing\GraphQL\Tests\Support\Models\Post;

class SinglePostQuery extends Query
{
    protected $attributes = [
        'name' => 'singlePostQuery',
    ];

    public function type(): Type
    {
        return GraphQL::type('Post');
    }

    public function args(): array
    {
        return [
            'id' => [
                'type' => Type::nonNull(Type::string()),
            ],
        ];
    }

    public function resolve($root, $args, $ctx, ResolveInfo $info, Closure $getSelectFields)
    {
        /** @var SelectFields $selectFields */
        $selectFields = $getSelectFields();

        return Post::with($selectFields->getRelations())
            ->select($selectFields->getSelect())
            ->where('id', (int)$args['id'])
            ->first();
    }
}
