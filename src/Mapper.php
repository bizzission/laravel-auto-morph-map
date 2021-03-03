<?php

declare(strict_types=1);

namespace SebastiaanLuca\AutoMorphMap;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use ReflectionClass;
use SebastiaanLuca\AutoMorphMap\Constants\CaseTypes;
use SebastiaanLuca\AutoMorphMap\Constants\NamingSchemes;
use Symfony\Component\Finder\Finder;

class Mapper
{
    protected static $scanned = false;
    
    protected static $modelMap = null;
    
    protected $command = null;
    
    /**
     * Scan all model directories and automatically alias the polymorphic types of Eloquent models.
     *
     * @return void
     */
    public function map() : void
    {
        if ($this->useCache()) {
            return;
        }
        if (self::$scanned) {
            return;
        }

        $map = $this->getMappedModels();

        $this->mapModels($map);
        
        self::$scanned = true;
    }
    
    public function getMappedModels($command = null)
    {

        $this->command = $command;
        if (!self::$modelMap) {
            $models = $this->getModels();
            self::$modelMap = $this->getModelMap($models);
        }
        return self::$modelMap;
    }

    /**
     * @return string
     */
    public function getCachePath() : string
    {
        return base_path('bootstrap/cache/morphmap.php');
    }

    /**
     * @return array
     */
    public function getModels() : array
    {
        $config = $this->getComposerConfig();
        $paths = $this->getModelPaths($config);

        if (count($paths) === 0) {
            return [];
        }

        return $this->scan($paths);
    }

    /**
     * @param array $models
     *
     * @return array
     */
    public function getModelMap(array $models): array
    {
        $map = [];

        $traitsToSkip = config('auto-morph-map.traits_to_skip') ?? [];

        foreach ($models as $model) {
            $reflected = new ReflectionClass($model);
            $count = count(array_intersect($traitsToSkip, $reflected->getTraitNames()));
            if ($count === 0) {
                $alias = $this->getModelAlias($model);
                if (Arr::exists($map, $alias) && app()->runningInConsole()) {
                    $previous = Arr::get($map, $alias);
                    $this->command->warn("-> $alias already set as $previous. Replaced by $model");
                }

                Arr::set($map, $alias, $model);
            }
        }
        return $map;
    }

    /**
     * @return bool
     */
    protected function useCache() : bool
    {
        if (! file_exists($cache = $this->getCachePath())) {
            return false;
        }

        $this->mapModels(include $cache);

        return true;
    }

    /**
     * @return array
     */
    protected function getComposerConfig() : array
    {
        $composer = file_get_contents(base_path('composer.json'));

        return json_decode($composer, true, JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array $config
     *
     * @return array
     */
    protected function getModelPaths(array $config) : array
    {
        $configPaths = config('auto-morph-map.paths');
        if ($configPaths && count($configPaths) > 0) {
            return $configPaths;
        }
        
        $paths = Arr::get($config, 'autoload.psr-4');

        $paths = collect($paths)
            ->unique()
            ->mapWithKeys(function (string $path, string $namespace) {
                return [$namespace => base_path(rtrim($path, '/'))];
            })
            ->filter(function (string $path) {
                return is_dir($path);
            });

        return $paths->toArray();
    }

    /**
     * @param array $paths
     *
     * @return array
     */
    protected function scan(array $paths) : array
    {
        $models = [];

        foreach ($paths as $namespace => $path) {
            foreach ((new Finder)->in($path)->files() as $file) {
                $name = str_replace(
                    ['/', '.php'],
                    ['\\', ''],
                    Str::after($file->getPathname(), $path . DIRECTORY_SEPARATOR)
                );

                $model = $namespace . $name;

                try {
                    if (!class_exists($model)) {
                        continue;
                    }
                } catch (Exception $e) {
                    continue;
                }

                $reflection = new ReflectionClass($model);

                if ($reflection->isAbstract() || ! is_subclass_of($model, Model::class)) {
                    continue;
                }

                $models[] = $model;
            }
        }

        return $models;
    }

    /**
     * @param array $map
     *
     * @return void
     */
    protected function mapModels(array $map) : void
    {
        $existing = Relation::morphMap() ?: [];

        if (count($existing) > 0) {
            $map = collect($map)
                ->reject(function (string $class, string $alias) use ($existing) : bool {
                    return array_key_exists($alias, $existing) || in_array($class, $existing, true);
                })
                ->toArray();
        }

        Relation::morphMap($map);
    }

    /**
     * @param string $model
     *
     * @return string
     */
    protected function getModelAlias(string $model) : string
    {
        $callback = config('auto-morph-map.conversion');

        if ($callback && is_callable($callback)) {
            $alias = $callback($model);
            if ($alias !== false) {
                return $alias;
            }
        }

        $name = $this->checkTraitsConversion($model);
        if ($name == null) {
            $name = $this->getModelName($model);
        }

        switch (config('auto-morph-map.case')) {
            case CaseTypes::SNAKE_CASE:
                return Str::snake($name);

            case CaseTypes::SLUG_CASE:
                return Str::slug($name);

            case CaseTypes::CAMEL_CASE:
                return Str::camel($name);

            case CaseTypes::STUDLY_CASE:
                return Str::studly($name);

            case CaseTypes::NONE:
            default:
                return $name;
        }
    }
    
    private function checkTraitsConversion(string $model)
    {
        $reflected = new ReflectionClass($model);
        $traitsName = $reflected->getTraitNames();

        $traitList = config('auto-morph-map.traits_conversion');

        foreach ($traitList as $trait => $method) {
            if (in_array($trait, $traitsName)) {
                return app($model)->{$method}($model);
            }
        }
        return null;
    }
    
    private function checkTraitsConversion(string $model)
    {
        $reflected = new ReflectionClass($model);
        $traitsName = $reflected->getTraitNames();

        $traitList = config('auto-morph-map.traits_conversion');

        foreach ($traitList as $trait => $method) {
            if (in_array($trait, $traitsName)) {
                switch ($method) {
                    case NamingSchemes::CLASS_BASENAME:
                        return class_basename($model);
                    default:
                        return app($model)->{$method}($model);
                }
            }
        }
        return null;
    }

    /**
     * @param string $model
     *
     * @return string
     */
    private function getModelName(string $model) : string
    {
        switch (config('auto-morph-map.naming')) {
            case NamingSchemes::TABLE_NAME:
                return app($model)->getTable();

            case NamingSchemes::CLASS_BASENAME:
                return class_basename($model);

            case NamingSchemes::SINGULAR_TABLE_NAME:
            default:
                return Str::singular(
                    app($model)->getTable()
                );
        }
    }
}
