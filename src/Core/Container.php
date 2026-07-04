<?php
namespace App\Core;

use ReflectionClass;
use ReflectionNamedType;
use Exception;

class Container
{
    private array $instances = [];

    public function set(string $id, object $service): void
    {
        $this->instances[$id] = $service;
    }

    public function get(string $class, array $runtimeDependencies = []): object 
    {
        if (isset($runtimeDependencies[$class])) {
            return $runtimeDependencies[$class];
        }

        if (isset($this->instances[$class])) {
            $instance = $this->instances[$class];

            /**
             * FACTORY CLOSURE
             */
            if ($instance instanceof \Closure) {

                $instance = $instance($this);

                $this->instances[$class] = $instance;
            }

            return $instance;
        }

        $this->instances[$class] = $this->autowire($class, $runtimeDependencies);

        return $this->instances[$class];
    }

    private function autowire(string $class, array $runtimeDependencies): ?object
    {
        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new Exception(
                "Class $class is not instantiable"
            );
        }

        $constructor = $reflection->getConstructor();

        if (!$constructor) {

            $instance = new $class();

            $this->instances[$class] = $instance;

            return $instance;
        }

        $dependencies = [];

        foreach ($constructor->getParameters() as $param) {

            $type = $param->getType();

            if (
                !$type instanceof ReflectionNamedType
            ) {
                throw new Exception(
                    "Cannot resolve {$param->getName()}"
                );
            }

            /**
             * BUILTIN TYPES
             */
            if ($type->isBuiltin()) {

                if ($param->isDefaultValueAvailable()) {

                    $dependencies[] = $param->getDefaultValue();

                    continue;
                }

                throw new Exception(
                    "Cannot autowire scalar {$param->getName()}"
                );
            }

            /**
             * OBJECT DEPENDENCIES
             */
            $dependencies[] = $this->get(
                $type->getName(),
                $runtimeDependencies
            );
        }

        return $reflection->newInstanceArgs(
            $dependencies
        );
    }
}