<?php

namespace FastLaravel\Http\Server;

use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Container\Container;
use FastLaravel\Http\Server\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;

class Sandbox
{
    /**
     * @var \FastLaravel\Http\Server\Application
     */
    protected $application;

    /**
     * @var \FastLaravel\Http\Server\Application
     */
    protected $snapshot;

    /**
     * @var Repository
     */
    protected $config;

    /**
     * @var Request
     */
    protected $request;

    /**
     * @var array
     */
    protected $providers = [];

    /**
     * Make a sandbox.
     *
     * @param \FastLaravel\Http\Server\Application
     *
     * @return Sandbox
     */
    public static function make(Application $application)
    {
        return new static($application);
    }

    /**
     * Sandbox constructor.
     * @param \FastLaravel\Http\Server\Application
     */
    public function __construct(Application $application)
    {
        $this->setApplication($application);
        $this->setInitialConfig();
        $this->setInitialProviders();
    }

    /**
     * Set a base application
     *
     * @param \FastLaravel\Http\Server\Application
     */
    public function setApplication(Application $application)
    {
        $this->application = $application;
    }

    /**
     * Set current request.
     *
     * @param Request
     */
    public function setRequest(Request $request)
    {
        $this->request = $request;
    }

    /**
     * @throws
     * Set config snapshot.
     */
    protected function setInitialConfig()
    {
        $this->config = clone $this->application->getApplication()->make('config');
    }

    /**
     * Initialize customized service providers.
     */
    protected function setInitialProviders()
    {
        $application = $this->application->getApplication();
        $providers = $this->config->get('swoole_http.providers', []);

        foreach ($providers as $provider) {
            if (class_exists($provider)) {
                $provider = new $provider($application);
                $this->providers[get_class($provider)] = $provider;
            }
        }
    }

    /**
     * Get an application snapshot
     *
     * @return \FastLaravel\Http\Server\Application
     */
    public function getApplication()
    {
        if ($this->snapshot instanceOf Application) {
            return $this->snapshot;
        }

        $snapshot = clone $this->application;

        $this->resetLaravelApp($snapshot->getApplication());
        $this->rebindLaravelApp($snapshot->getApplication(), $snapshot->kernel());

        return $this->snapshot = $snapshot;
    }

    /**
     * Reset Laravel Application.
     */
    protected function resetLaravelApp($application)
    {
        $this->resetConfigInstance($application);
        $this->resetSession($application);
        $this->resetCookie($application);
        $this->resetInstances($application);
        $this->resetProviders($application);
    }

    /**
     * rebind Laravel Application.
     */
    protected function rebindLaravelApp($application, $kernel)
    {
        $this->rebindKernelContainer($application, $kernel);
        $this->rebindRequest($application);
        $this->rebindRouterContainer($application);
        $this->rebindViewContainer($application);
    }

    /**
     * Rebind laravel's container in kernel.
     */
    protected function rebindKernelContainer($application, $kernel)
    {
        // 用$application替换$kernel->app值
        $closure = function () use ($application) {
            $this->app = $application;
        };
        // 复制当前闭包对象，绑定指定的$this对象和类作用域。
        $resetKernel = $closure->bindTo($kernel, $kernel);
        $resetKernel();
    }

    /**
     * Clear resolved instances.
     */
    protected function resetInstances($application)
    {
        $instances = $this->config->get('swoole_http.instances', []);
        foreach ($instances as $instance) {
            $application->forgetInstance($instance);
        }
    }

    /**
     * Re-register and reboot service providers.
     */
    protected function resetProviders($application)
    {
        foreach ($this->providers as $provider) {
            $this->rebindProviderContainer($provider, $application);
            if (method_exists($provider, 'register')) {
                $provider->register();
            }
            if (method_exists($provider, 'boot')) {
                $application->call([$provider, 'boot']);
            }
        }
    }

    /**
     * Reset laravel's config to initial values.
     */
    protected function resetConfigInstance($application)
    {
        $application->instance('config', clone $this->config);
    }

    /**
     * Reset laravel's session data.
     */
    protected function resetSession($application)
    {
        if (isset($application['session'])) {
            $session = $application->make('session');
            $session->flush();
        }
    }

    /**
     * Reset laravel's cookie.
     */
    protected function resetCookie($application)
    {
        if (isset($application['cookie'])) {
            $cookies = $application->make('cookie');
            foreach ($cookies->getQueuedCookies() as $key => $value) {
                $cookies->unqueue($key);
            }
        }
    }

    /**
     * Bind illuminate request to laravel application.
     */
    protected function rebindRequest($application)
    {
        if ($this->request instanceof Request) {
            $application->instance('request', $this->request);
        }
    }

    /**
     * Rebind service provider's container.
     */
    protected function rebindProviderContainer($provider, $application)
    {
        $closure = function () use ($application) {
            $this->app = $application;
        };

        $resetProvider = $closure->bindTo($provider, $provider);
        $resetProvider();
    }

    /**
     * Rebind laravel's container in router.
     */
    protected function rebindRouterContainer($application)
    {
        if ($this->isFramework('laravel')) {
            $router = $application->make('router');
            $request = $this->request;
            $closure = function () use ($application, $request) {
                $this->container = $application;
                if (is_null($request)) {
                    return;
                }
                try {
                    $route = $this->routes->match($request);
                    // clear resolved controller
                    if (property_exists($route, 'container')) {
                        $route->controller = null;
                    }
                    // rebind matched route's container
                    $route->setContainer($application);
                } catch (\Exception $e) {
                    // may be dingo
                    return;
                }
            };

            $resetRouter = $closure->bindTo($router, $router);
            $resetRouter();
        }
    }

    /**
     * Rebind laravel's container in view.
     */
    protected function rebindViewContainer($application)
    {
        $view = $application->make('view');

        $closure = function () use ($application) {
            $this->container = $application;
            $this->shared['app'] = $application;
        };

        $resetView = $closure->bindTo($view, $view);
        $resetView();
    }

    /**
     * Get application's framework.
     */
    protected function isFramework(string $name)
    {
        return $this->application->getFramework() === $name;
    }

    /**
     * Get a laravel snapshot
     *
     * @return Container
     */
    public function getLaravelApp()
    {
        if ($this->snapshot instanceOf Application) {
            return $this->snapshot->getApplication();
        }

        return $this->getApplication()->getApplication();
    }

    /**
     * Set laravel snapshot to container and facade.
     */
    public function enable()
    {
        $this->setInstance($this->getLaravelApp());
    }

    /**
     * Set original laravel app to container and facade.
     */
    public function disable()
    {
        if ($this->snapshot instanceOf Application) {
            $this->snapshot = null;
        }

        $this->request = null;
    }

    /**
     * Replace app's self bindings.
     */
    protected function setInstance($application)
    {
        $application->instance('app', $application);
        $application->instance(Container::class, $application);

        Container::setInstance($application);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($application);
    }
}
