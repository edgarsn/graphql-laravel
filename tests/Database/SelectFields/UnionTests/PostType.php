<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Tests\Database\SelectFields\UnionTests;

use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Type as GraphQLType;
use Rebing\GraphQL\Tests\Support\Models\Post;

class PostType extends GraphQLType
{
    protected $attributes = [
        'name' => 'Post',
        'model' => Post::class,
    ];

    public function fields(): array
    {
        return [
            'body' => [
                'type' => Type::string(),
            ],
            'comments' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(GraphQL::type('Comment')))),
                'query' => function (array $args, $query, $ctx) {
                    // this is never being called through UnionType!
                    dump('custom query got called');

                    $query->where('title', 'like', 'lorem');
                },
            ],
            'id' => [
                'type' => Type::nonNull(Type::ID()),
            ],
            'title' => [
                'type' => Type::nonNull(Type::string()),
            ],
        ];
    }
}
