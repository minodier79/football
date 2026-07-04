<?php
namespace App\Http;

use App\Core\Container;
use App\Exception\NotFoundException;
use App\Http\RequestContext;

class Router
{
    public function __construct(private Container $container) {}

    public function dispatch(string $method, string $uri): void
    {
        $path = trim(parse_url($uri, PHP_URL_PATH), '/');
        $segments = array_values(array_filter(explode('/', $path)));
        $entity = rtrim($segments[0], 's');
        if (empty($segments)) {
            throw new NotFoundException();
        }
        $params = $this->getContextParams($uri, $entity);
        $data = 'GET' == $method ? null : $this->getData();
        $id       = $segments[1] ?? null;
        //$sub      = $segments[2] ?? null;
        $controller = $this->resolveController($params);
        //$controller = $sub && $id !== null ? $this->resolveNestedController($resource, $sub, $params) : $this->resolveController($resource, $params);
        //$action = !$id ? $this->resolveAction($method, null) : (($id !== null && ctype_digit($id)) ? $this->resolveAction($method, $id) : $id);
        $action = $id ? $this->resolveAction($method, $id) : $this->resolveAction($method, null);
        if (!method_exists($controller, $action)) {
            throw new NotFoundException();
        }
        //$id && $action != $id ? $controller->$action($id) : $controller->$action();
        $id ? $controller->$action($id, $data) : ($data ? $controller->$action($data) : $controller->$action());
    }

    private function getContextParams(string $uri, string $entity): array
    {
        parse_str(parse_url($uri, PHP_URL_QUERY) ?? '', $query);

        $context = new RequestContext(
            entity: $entity,
            limit: (int)($query['limit'] ?? 100),
            start: (int)($query['start'] ?? 0),
            search: $query['search'] ?? null,
            sort: $query['sort'] ?? null,
            order: $query['order'] ?? 'asc'
        );

        return [RequestContext::class => $context];
    }

    private function resolveController(array $params): object
    {
        $class = "App\\Controller\\EntityController";

        if (!class_exists($class)) {
          throw new NotFoundException('test');
            throw new NotFoundException();
        }

        return $this->container->get($class, $params);
    }

    private function getData(): array
    {
      $rawBody = file_get_contents(
          'php://input'
      );

      $data = json_decode(
          $rawBody,
          true
      );

      if (
          $rawBody !== ''
          && json_last_error() !== JSON_ERROR_NONE
      ) {
          throw new \Exception(
              'Invalid JSON body'
          );
      }

      return $data;
    }

    private function resolveController2(string $resource, array $params): object
    {
        $name = ucfirst(rtrim($resource, 's')) . 'Controller';
        $class = "App\\Controller\\$name";

        if (!class_exists($class)) {
          throw new NotFoundException('test');
            throw new NotFoundException();
        }

        return $this->container->get($class, $params);
    }

    private function resolveNestedController(string $parent, string $child, array $params): object
    {
        $parent = ucfirst(rtrim($parent, 's'));
        $child  = ucfirst(rtrim($child, 's'));

        $class = "App\\Controller\\{$parent}{$child}Controller";

        if (!class_exists($class)) {
            throw new NotFoundException();
        }

        return $this->container->get($class, $params);
    }

    private function resolveAction(string $method, ?string $id): string
    {
        return match ($method) {
            'GET'    => $id ? 'show' : 'index',
            'POST'   => 'insert',
            'PUT', 'PATCH' => 'update',
            'DELETE' => 'delete',
            default  => throw new \Exception('Method not allowed'),
        };
    }
}