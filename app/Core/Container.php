<?php

declare(strict_types=1);

namespace App\Core;

use LogicException;
use ReflectionClass;
use ReflectionNamedType;

/**
 * A small dependency injection container.
 *
 * Classes are built once and shared. A class with no explicit factory is
 * built by reading its constructor: each parameter typed with a class is
 * resolved the same way. Anything that needs configuration (a secret, a
 * setting) gets an explicit factory in config/services.php.
 */
final class Container
{
    /** @var array<string, callable(self): object> */
    private array $factories = [];

    /** @var array<string, object> */
    private array $instances = [];

    /** @var array<string, true> classes being built, to report cycles clearly */
    private array $building = [];

    /** @param callable(self): object $factory */
    public function bind(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        if (isset($this->building[$id])) {
            throw new LogicException("Circular dependency while building $id");
        }
        $this->building[$id] = true;
        try {
            $object = isset($this->factories[$id]) ? ($this->factories[$id])($this) : $this->autowire($id);
        } finally {
            unset($this->building[$id]);
        }
        return $this->instances[$id] = $object;
    }

    private function autowire(string $class): object
    {
        if (!class_exists($class)) {
            throw new LogicException("Cannot build $class: class not found");
        }
        $reflection = new ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new LogicException("Cannot build $class: not instantiable");
        }
        $arguments = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $arguments[] = $this->get($type->getName());
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
            } else {
                throw new LogicException(
                    "Cannot build $class: add a factory for it in config/services.php (parameter \${$parameter->getName()})"
                );
            }
        }
        return new $class(...$arguments);
    }
}
