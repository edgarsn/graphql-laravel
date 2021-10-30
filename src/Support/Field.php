<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Support;

use Closure;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type as GraphQLType;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Rebing\GraphQL\Error\AuthorizationError;
use Rebing\GraphQL\Error\ValidationError;
use Rebing\GraphQL\Support\AliasArguments\AliasArguments;
use ReflectionMethod;

/**
 * @property string $name
 */
abstract class Field
{
    /**
     * The depth the SelectField and ResolveInfoFieldsAndArguments classes traverse.
     *
     * @var int
     */
    protected $depth = 5;

    /** @var array */
    protected $attributes = [];

    /** @var string[] */
    protected $middleware = [];

    /**
     * Override this in your queries or mutations
     * to provide custom authorization.
     *
     * @param mixed $root
     * @param mixed $ctx
     */
    public function authorize($root, array $args, $ctx, ResolveInfo $resolveInfo = null, Closure $getSelectFields = null): bool
    {
        return true;
    }

    public function attributes(): array
    {
        return [];
    }

    abstract public function type(): GraphQLType;

    /**
     * @return array<string,array>
     */
    public function args(): array
    {
        return [];
    }

    /**
     * Define custom Laravel Validator messages as per Laravel 'custom error messages'.
     *
     * @param array $args submitted arguments
     */
    public function validationErrorMessages(array $args = []): array
    {
        return [];
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    protected function rules(array $args = []): array
    {
        return [];
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public function getRules(array $arguments = []): array
    {
        $rules = $this->rules($arguments);
        $argsRules = (new Rules($this->args(), $arguments))->get();

        return array_merge($argsRules, $rules);
    }

    /**
     * @param array<string,mixed> $fieldsAndArgumentsSelection
     */
    public function validateFieldArguments(array $fieldsAndArgumentsSelection): void
    {
        $argsRules = (new RulesInFields($this->type(), $fieldsAndArgumentsSelection))->get();

        if (count($argsRules)) {
            $validator = $this->getValidator($fieldsAndArgumentsSelection, $argsRules);

            if ($validator->fails()) {
                throw new ValidationError('validation', $validator);
            }
        }
    }

    public function getValidator(array $args, array $rules): ValidatorContract
    {
        // allow our error messages to be customised
        $messages = $this->validationErrorMessages($args);

        return Validator::make($args, $rules, $messages);
    }

    /**
     * @return array<string>
     */
    protected function getMiddleware(): array
    {
        return $this->middleware;
    }

    protected function getResolver(): ?Closure
    {
        $resolver = $this->originalResolver();

        if (!$resolver) {
            return null;
        }

        return function ($root, ...$arguments) use ($resolver) {
            $middleware = $this->getMiddleware();

            return app()->make(Pipeline::class)
                ->send(array_merge([$this], $arguments))
                ->through($middleware)
                ->via('resolve')
                ->then(function ($arguments) use ($middleware, $resolver, $root) {
                    $result = $resolver($root, ...array_slice($arguments, 1));

                    foreach ($middleware as $name) {
                        /** @var Middleware $instance */
                        $instance = app()->make($name);

                        if (method_exists($instance, 'terminate')) {
                            app()->terminating(function () use ($arguments, $instance, $result): void {
                                $instance->terminate($this, ...array_slice($arguments, 1), ...[$result]);
                            });
                        }
                    }

                    return $result;
                });
        };
    }

    protected function originalResolver(): ?Closure
    {
        if (!method_exists($this, 'resolve')) {
            return null;
        }

        $resolver = [$this, 'resolve'];
        $authorize = [$this, 'authorize'];

        return function () use ($resolver, $authorize) {
            // 0 - the "root" object; `null` for queries, otherwise the parent of a type
            // 1 - the provided `args` of the query or type (if applicable), empty array otherwise
            // 2 - the "GraphQL query context" (see \Rebing\GraphQL\GraphQLController::queryContext)
            // 3 - \GraphQL\Type\Definition\ResolveInfo as provided by the underlying GraphQL PHP library
            // 4 (!) - added by this library, encapsulates creating a `SelectFields` instance
            $arguments = func_get_args();

            // Validate mutation arguments
            $args = $arguments[1];

            $rules = $this->getRules($args);

            if (count($rules)) {
                $validator = $this->getValidator($args, $rules);

                if ($validator->fails()) {
                    throw new ValidationError('validation', $validator);
                }
            }

            $fieldsAndArguments = (new ResolveInfoFieldsAndArguments($arguments[3]))->getFieldsAndArgumentsSelection($this->depth);

            // Validate arguments in fields
            $this->validateFieldArguments($fieldsAndArguments);

            $arguments[1] = $this->getArgs($arguments);

            // Authorize
            if (true != call_user_func_array($authorize, $arguments)) {
                throw new AuthorizationError($this->getAuthorizationMessage());
            }

            $method = new ReflectionMethod($this, 'resolve');

            $additionalParams = array_slice($method->getParameters(), 3);

            $additionalArguments = array_map(function ($param) use ($arguments, $fieldsAndArguments) {
                $paramType = $param->getType();

                if ($paramType->isBuiltin()) {
                    throw new InvalidArgumentException("'{$param->name}' could not be injected");
                }

                $className = $param->getType()->getName();

                if (Closure::class === $className) {
                    return function (int $depth = null) use ($arguments, $fieldsAndArguments): SelectFields {
                        return $this->instanciateSelectFields($arguments, $fieldsAndArguments, $depth);
                    };
                }

                if (SelectFields::class === $className) {
                    return $this->instanciateSelectFields($arguments, $fieldsAndArguments, null);
                }

                if (ResolveInfo::class === $className) {
                    return $arguments[3];
                }

                return app()->make($className);
            }, $additionalParams);

            return call_user_func_array($resolver, array_merge(
                [$arguments[0], $arguments[1], $arguments[2]],
                $additionalArguments
            ));
        };
    }

    /**
     * @param array<int,mixed> $arguments
     * @param int $depth
     * @param array<string,mixed> $fieldsAndArguments
     */
    private function instanciateSelectFields(array $arguments, array $fieldsAndArguments, int $depth = null): SelectFields
    {
        $ctx = $arguments[2] ?? null;

        if (null !== $depth && $depth !== $this->depth) {
            $fieldsAndArguments = (new ResolveInfoFieldsAndArguments($arguments[3]))
                ->getFieldsAndArgumentsSelection($depth);
        }

        return new SelectFields($this->type(), $arguments[1], $ctx, $fieldsAndArguments);
    }

    protected function aliasArgs(array $arguments): array
    {
        return (new AliasArguments($this->args(), $arguments[1]))->get();
    }

    protected function getArgs(array $arguments): array
    {
        return $this->aliasArgs($arguments);
    }

    /**
     * Get the attributes from the container.
     */
    public function getAttributes(): array
    {
        $attributes = $this->attributes();

        $attributes = array_merge(
            $this->attributes,
            ['args' => $this->args()],
            $attributes
        );

        $attributes['type'] = $this->type();

        $resolver = $this->getResolver();

        if (isset($resolver)) {
            $attributes['resolve'] = $resolver;
        }

        return $attributes;
    }

    public function getAuthorizationMessage(): string
    {
        return 'Unauthorized';
    }

    /**
     * Convert the Fluent instance to an array.
     */
    public function toArray(): array
    {
        return $this->getAttributes();
    }

    /**
     * Dynamically retrieve the value of an attribute.
     *
     * @param string $key
     *
     * @return mixed
     */
    public function __get($key)
    {
        $attributes = $this->getAttributes();

        return $attributes[$key] ?? null;
    }

    public function __set(string $key, $value): void
    {
        $this->attributes[$key] = $value;
    }
}
