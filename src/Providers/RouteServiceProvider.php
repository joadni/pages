<?php

namespace Netflex\Pages\Providers;

use Throwable;
use ReflectionClass;

use API;

use Netflex\Pages\Page;
use Netflex\Pages\Middleware\BindPage;
use Netflex\Pages\Middleware\GroupAuthentication;
use Netflex\Pages\Controllers\PageController;
use Netflex\Pages\Controllers\Controller;
use Netflex\Pages\Middleware\JwtProxy;
use Netflex\Foundation\Redirect;
use Netflex\Pages\JwtPayload;
use Netflex\Pages\PreviewRequest;
use Netflex\Pages\Attributes\Route as RouteAttribute;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class RouteServiceProvider extends ServiceProvider
{
  /**
   * This namespace is applied to your controller routes.
   *
   * In addition, it is set as the URL generator's root namespace.
   *
   * @var string
   */
  protected $namespace = 'App\Http\Controllers';

  /**
   * The path to the "home" route for your application.
   *
   * @var string
   */
  public const HOME = '/';

  /**
   * Define your route model bindings, pattern filters, etc.
   *
   * @return void
   */
  public function boot()
  {
    $this->registerMiddlewareGroups();
    parent::boot();

    Request::macro('page', function () {
      return current_page();
    });
  }

  protected function registerMiddlewareGroups()
  {
    $router = $this->app->make('router');

    $router->aliasMiddleware('jwt_proxy', JwtProxy::class);
    $router->aliasMiddleware('bind_page', BindPage::class);
    $router->aliasMiddleware('group_auth', GroupAuthentication::class);

    $router->middlewareGroup('netflex', [
      'web',
      'bind_page',
      'group_auth'
    ]);
  }

  /**
   * Define the routes for the application.
   *
   * @return void
   */
  public function map()
  {
    $this->mapApiRoutes();
    $this->mapNetflexRoutes();
    $this->mapRedirects();
    $this->mapWebRoutes();
    $this->mapRobots();
    $this->mapSitemap();
    //
  }

  /**
   * Define the "web" routes for the application.
   *
   * These routes all receive session state, CSRF protection, etc.
   *
   * @return void
   */
  protected function mapWebRoutes()
  {
    Route::middleware('web')
      ->namespace($this->namespace)
      ->group(base_path('routes/web.php'));
  }

  /**
   * Define the "api" routes for the application.
   *
   * These routes are typically stateless.
   *
   * @return void
   */
  protected function mapApiRoutes()
  {
    Route::prefix('api')
      ->middleware('api')
      ->namespace($this->namespace)
      ->group(base_path('routes/api.php'));
  }

  protected function mapRedirects()
  {
    Collection::make(Redirect::all())
      ->each(function ($redirect) {
        Route::redirect(
          $redirect->source_url,
          $redirect->target_url,
          $redirect->type
        )->name($redirect->id);
      });
  }

  protected function handlePage(Request $request, JwtPayload $payload)
  {
    if ($page = Page::findOrFail($payload->page_id)) {
      if ($payload->revision_id ?? false) {
        $page->loadRevision($payload->revision_id);
      }

      current_page($page);

      $controller = $page->template->controller ?? null;
      $pageController = PageController::class;
      $class = trim($controller ? ("\\{$this->namespace}\\{$controller}") : "\\{$pageController}", '\\');

      if (!$class) {
        $page->toResponse($request);
      }

      $controller = app($class);

      if (method_exists($controller, 'index')) {
        return $this->callWithInjectedDependencies($controller, 'index');
      }

      return $controller->fallbackIndex();
    }
  }

  protected function handleEntry(Request $request, JwtPayload $payload)
  {
    if ($payload->structure_id) {
      $structure = Cache::rememberForever('structure/' . $payload->structure_id, function () use ($payload) {
        return API::get('builder/structures/' . $payload->structure_id);
      });
    }

    if (!$structure) {
      abort(404, 'Structure not found or not set in payload.');
    }

    if (!isset($structure->config->previewController)) {
      abort(400, 'previewController setting missing or misformed in structure config.');
    }

    list($controller, $action) = explode('@', $structure->config->previewController->value);

    $controller = trim("\\{$this->namespace}\\{$controller}", '\\');

    if (!$controller || !$action) {
      abort(400, 'previewController setting missing or misformed in structure config.');
    }

    $previewRequest = new PreviewRequest($payload);

    return app($controller)->{$action}($previewRequest);
  }

  protected function handleExtension(Request $request, $payload)
  {
    if ($alias = $payload->view) {
      if ($extension = resolve_extension($alias, json_decode(json_encode($payload), true))) {
        return $extension->handle($request);
      }
    }

    return abort(404);
  }

  protected function callWithInjectedDependencies($controller, $method = 'index', $arguments = [])
  {
    return $controller->$method(...$this->injectDependencies($controller, $method, $arguments));
  }

  /**
   * @param \Illuminate\Routing\Controller $class
   * @param string $method
   * @param array $arguments
   * @return array
   */
  protected function injectDependencies(\Illuminate\Routing\Controller $class, $method = 'index', $arguments = [])
  {
    $reflector = new ReflectionClass($class);
    if ($reflector->hasMethod($method)) {
      $params = $reflector->getMethod($method)->getParameters();
      if ($param = array_shift($params)) {
        if ($param && $param->hasType() && !$param->isDefaultValueAvailable() && !$param->isOptional() && $param->getType()->getName() === \Illuminate\Http\Request::class) {
          array_unshift($arguments, app('request'));
        }

        return $arguments;
      }
    }

    return [];
  }

  protected function mapNetflexRoutes()
  {
    Route::get('.well-known/netflex/CacheStore', function (Request $request) {
      if ($key = $request->get('key')) {
        if (Cache::has($key)) {
          Cache::forget($key);
          return ['success' => true, 'message' => 'Key deleted'];
        }

        return ['success' => false, 'message' => 'Key does not exist'];
      }

      return ['success' => false, 'message' => 'Key is missing'];
    });

    Route::middleware('jwt_proxy')
      ->group(function () {
        Route::any('.well-known/netflex', function (Request $request) {
          if ($payload = jwt_payload()) {
            current_mode($payload->mode);
            editor_tools($payload->edit_tools);
            URL::forceRootUrl($payload->domain);

            switch ($payload->relation) {
              case 'page':
                return $this->handlePage($request, $payload);
              case 'entry':
                return $this->handleEntry($request, $payload);
              case 'extension':
                return $this->handleExtension($request, $payload);
              default:
                break;
            }
          }

          abort(400);
        })->name('Netflex Editor Proxy');
      });

    Route::middleware('netflex')
      ->group(function () {
        Page::all()->filter(function ($page) {
          return $page->type === 'page' && $page->template && $page->published;
        })->each(function ($page) {
          $controller = $page->template->controller ?? null;
          $pageController = Config::get('pages.controller', PageController::class);
          $class = trim($controller ? ("\\{$this->namespace}\\{$controller}") : "\\{$pageController}", '\\');

          try {
            tap(new $class, function (Controller $controller) use ($page) {
              $class = get_class($controller);
              $routeDefintions = $controller->getRoutes();

              if (PHP_MAJOR_VERSION >= 8) {
                $reflectionClass = new ReflectionClass($class);
                /** @var RouteAttribute[] */
                $routes = $reflectionClass->getAttributes(RouteAttribute::class);
                foreach ($routes as $route) {
                  $routeDefintions->prepend((object) [
                    'url' => $route->url,
                    'action' => $route->action,
                    'methods' => is_array($route->methods) ? $route->methods : [$route->methods]
                  ]);
                }
              }

              foreach ($routeDefintions as $routeDefintion) {
                $routeDefintion->url = trim($routeDefintion->url, '/');
                $url = trim("{$page->url}/{$routeDefintion->url}", '/');
                $action = "$class@{$routeDefintion->action}";

                $route = Route::domain('');

                if ($domain = $page->domain) {
                  $route = $route->domain($domain);
                }

                $route = $route->match($routeDefintion->methods, $url, $action)
                  ->name($page->id);

                $this->app->bind(route_hash($route), function () use ($page) {
                  return $page;
                });
              }
            });
          } catch (Throwable $e) {
            if (App::environment() !== 'master') {
              throw $e;
            }

            Log::warning("Route {$page->url} could not be registered because {$e->getMessage()}");
          }
        });
      });
  }

  protected function mapRobots()
  {
    Route::get('robots.txt', function () {
      $production = app()->env === 'master';

      return response(view('nf::robots', ['production' => $production]), 200, ['Content-Type' => 'text/plain']);
    })->name('Robots Exclusion Protocol');
  }

  protected function mapSitemap()
  {
    Route::get('/sitemap.xml', function () {
      $entries = $this->getSitemapEntries();

      return response(view('nf::sitemap-xml', ['entries' => $entries]), 200, ['Content-Type' => 'application/xml']);
    })->name('Sitemap');

    Route::get('/sitemap.xsl', function () {
      return response(view('nf::sitemap-xsl'), 200, ['Content-Type' => 'text/xsl']);
    })->name('Sitemap Stylsheet');
  }

  protected function getSitemapEntries()
  {
    return [];
  }
}
