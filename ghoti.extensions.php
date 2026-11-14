<?php
declare(strict_types=1);

/** Public extension contract. Increment only for incompatible changes. */
const GHOTI_MODULE_API = 1;

/** Installed manifests describe trusted server code; discovery never executes it. */
final class GhotiModuleRegistry
{
    public const BUILT_INS = ['pages','links','login','banners','analytics','gallery','filemanager','mail','boards','vhosts','store','bpong'];
    private ?array $definitions = null;

    public function __construct(private string $root) {}

    public function getDefinitions(): array
    {
        if ($this->definitions !== null) return $this->definitions;
        $definitions = [];
        $root = realpath($this->root);
        if ($root === false) throw new RuntimeException('The module directory is unavailable.');
        $paths = glob($root.'/*/module.json');
        if ($paths === false) throw new RuntimeException('Could not discover module manifests.');
        sort($paths, SORT_STRING);
        foreach ($paths as $path) {
            $id = basename(dirname($path));
            if (!self::isValidId($id) || in_array($id, self::BUILT_INS, true)) {
                throw new RuntimeException('Invalid or reserved extension module name: '.$id);
            }
            $directory = realpath(dirname($path));
            if ($directory !== $root.'/'.$id || realpath($path) !== $directory.'/module.json') {
                throw new RuntimeException('Module manifests must be regular files in their own module directory.');
            }
            if (filesize($path) > 65536) throw new RuntimeException('Module manifest exceeds 64 KiB: '.$id);
            $json = file_get_contents($path);
            if ($json === false) throw new RuntimeException('Could not read module manifest: '.$id);
            try { $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR); }
            catch (JsonException $error) { throw new RuntimeException('Invalid module manifest JSON: '.$id, 0, $error); }
            if (!is_array($data) || ($data['apiVersion'] ?? null) !== GHOTI_MODULE_API) {
                throw new RuntimeException('Unsupported module API version: '.$id);
            }
            foreach (['name'=>100,'description'=>500] as $key=>$limit) {
                if (!is_string($data[$key] ?? null) || trim($data[$key]) === '' || strlen($data[$key]) > $limit) {
                    throw new RuntimeException('Invalid '.$key.' in module manifest: '.$id);
                }
            }
            $requires = $data['requires'] ?? [];
            if (!is_array($requires) || !array_is_list($requires) || count($requires) > 32) {
                throw new RuntimeException('Invalid module dependencies: '.$id);
            }
            foreach ($requires as $dependency) {
                if (!self::isValidId($dependency) || $dependency === $id) throw new RuntimeException('Invalid module dependency: '.$id);
            }
            if (!is_bool($data['schema'] ?? false)) throw new RuntimeException('Invalid schema flag: '.$id);
            $legacy = $data['legacyEnabledSetting'] ?? null;
            if ($legacy !== null && (!is_string($legacy) || !preg_match('/^enable[A-Z][A-Za-z0-9]{0,63}$/D', $legacy))) {
                throw new RuntimeException('Invalid legacy setting name: '.$id);
            }
            foreach ([$id.'.php',$id.'.async.php',...(($data['schema'] ?? false) ? [$id.'.sql'] : [])] as $file) {
                if (!is_file($directory.'/'.$file) || realpath($directory.'/'.$file) !== $directory.'/'.$file) {
                    throw new RuntimeException('Missing or linked module file: '.$id.'/'.$file);
                }
            }
            $definitions[$id] = ['name'=>$data['name'],'description'=>$data['description'],'requires'=>array_values(array_unique($requires)),
                'schema'=>$data['schema'] ?? false,'legacyEnabledSetting'=>$legacy];
        }
        return $this->definitions = $definitions;
    }

    public static function isValidId(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[a-z][a-z0-9_]{0,47}$/D', $id) === 1;
    }

    /** Retain unavailable modules' settings, but never treat them as installed code. */
    public function readSettings(array $settings): array
    {
        $selection = [];
        $saved = $settings['extensionModules'] ?? [];
        if (!is_array($saved)) throw new RuntimeException('extensionModules must be a module-to-boolean map.');
        foreach ($saved as $id=>$enabled) {
            if (!self::isValidId($id) || !in_array($enabled, [true,false,0,1,'0','1'], true)) {
                throw new RuntimeException('Invalid saved extension module setting.');
            }
            $selection[$id] = (bool)$enabled;
        }
        foreach ($this->getDefinitions() as $id=>$definition) {
            $legacy = $definition['legacyEnabledSetting'];
            if (!array_key_exists($id, $selection) && $legacy !== null && array_key_exists($legacy, $settings)) {
                $selection[$id] = in_array($settings[$legacy], [true,1,'1'], true);
            }
        }
        return $selection;
    }

    /** Dependencies load first; disabled or missing dependencies disable their dependents. */
    public function getEnabled(array $selection, array $builtIns): array
    {
        $definitions = $this->getDefinitions();
        $visiting = []; $resolved = []; $ordered = [];
        $visit = function(string $id) use (&$visit,&$visiting,&$resolved,&$ordered,$definitions,$selection,$builtIns): bool {
            if (in_array($id, $builtIns, true)) return true;
            if (isset($resolved[$id])) return $resolved[$id];
            if (isset($visiting[$id])) throw new RuntimeException('Circular module dependency: '.$id);
            if (empty($selection[$id]) || !isset($definitions[$id])) return $resolved[$id] = false;
            $visiting[$id] = true;
            foreach ($definitions[$id]['requires'] as $dependency) {
                if (!$visit($dependency)) { unset($visiting[$id]); return $resolved[$id] = false; }
            }
            unset($visiting[$id]);
            $ordered[] = $id;
            return $resolved[$id] = true;
        };
        foreach (array_keys($definitions) as $id) $visit($id);
        return $ordered;
    }

    public function validateSelection(mixed $input, array $current, array $builtIns): array
    {
        if (!is_array($input) || count($input) > 128) throw new InvalidArgumentException('Invalid extension module selection.');
        $definitions = $this->getDefinitions();
        foreach ($input as $id=>$enabled) {
            if (!self::isValidId($id) || !isset($definitions[$id]) || !in_array($enabled, [true,false,0,1,'0','1'], true)) {
                throw new InvalidArgumentException('Choose only installed modules using their enabled checkboxes.');
            }
            $current[$id] = (bool)$enabled;
        }
        try { $enabled = $this->getEnabled($current, $builtIns); }
        catch (RuntimeException $error) { throw new InvalidArgumentException($error->getMessage(), 0, $error); }
        foreach ($definitions as $id=>$definition) {
            if (!empty($current[$id]) && !in_array($id, $enabled, true)) {
                throw new InvalidArgumentException($definition['name'].' requires these modules to be installed and enabled: '.implode(', ', $definition['requires']));
            }
        }
        return $current;
    }

    public function allowsSchema(string $id): bool
    {
        return self::isValidId($id) && !empty($this->getDefinitions()[$id]['schema']);
    }
}

function ghoti_extensions(): GhotiModuleRegistry
{
    static $registry;
    return $registry ??= new GhotiModuleRegistry(__DIR__.'/mod');
}

/** Hooks are registered by trusted PHP modules, never by request parameters. */
final class GhotiHooks
{
    private static array $actions = [];
    private static array $filters = [];

    private static function validateName(string $name): void
    {
        if (!preg_match('/^[a-z][a-z0-9_.-]{0,79}$/D', $name)) throw new InvalidArgumentException('Invalid hook name.');
    }
    public static function addAction(string $name, callable $callback, int $priority): void
    {
        self::validateName($name);
        self::$actions[$name][$priority][] = $callback;
    }
    public static function addFilter(string $name, callable $callback, int $priority): void
    {
        self::validateName($name);
        self::$filters[$name][$priority][] = $callback;
    }
    public static function runAction(string $name, array $context): void
    {
        $groups = self::$actions[$name] ?? [];
        ksort($groups, SORT_NUMERIC);
        foreach ($groups as $callbacks) foreach ($callbacks as $callback) $callback($context);
    }
    public static function applyFilters(string $name, mixed $value, array $context): mixed
    {
        $groups = self::$filters[$name] ?? [];
        ksort($groups, SORT_NUMERIC);
        foreach ($groups as $callbacks) foreach ($callbacks as $callback) $value = $callback($value, $context);
        return $value;
    }
}
function ghoti_add_hook(string $name, callable $callback, int $priority=10): void { GhotiHooks::addAction($name,$callback,$priority); }
function ghoti_run_hook(string $name, array $context=[]): void { GhotiHooks::runAction($name,$context); }
function ghoti_add_filter(string $name, callable $callback, int $priority=10): void { GhotiHooks::addFilter($name,$callback,$priority); }
function ghoti_apply_filters(string $name, mixed $value, array $context=[]): mixed { return GhotiHooks::applyFilters($name,$value,$context); }
