<?php

declare(strict_types=1);

namespace Selective\PHPUnit\Phpunit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestSuite;
use Selective\PHPUnit\Paths;
use Selective\PHPUnit\ShortId;
use Selective\PHPUnit\Version;

final class Engine
{
    private const PER_RUN_STATE_NAMESPACE = 'PHPUnit\\Framework\\TestCase\\';

    private array $cases = [];

    private array $order = [];

    private array $traces = [];

    private array $built = [];

    private array $satisfied = [];

    private bool $started = false;

    private Tracer $tracer;

    private Outcomes $outcomes;

    private Reports $reports;

    public function __construct()
    {
        $this->tracer = new Tracer();
        $this->outcomes = new Outcomes();
        $this->reports = new Reports();
    }

    public function boot(array $args): void
    {
        if (!class_exists(\PHPUnit\TextUI\Configuration\Registry::class)) {
            fwrite(STDERR, "Selective could not find PHPUnit. Install phpunit/phpunit alongside selective/phpunit.\n");
            exit(1);
        }
        $args = Arguments::strip($args);
        $cli = $this->cli($args);
        $finder = $this->make(\PHPUnit\TextUI\CliArguments\XmlConfigurationFileFinder::class);
        $found = $finder->find($cli);
        if (is_string($found) && $found !== '') {
            $copy = ConfigCopy::write($found);
            $args = Arguments::withConfiguration($args, $copy);
            $cli = $this->cli($args);
            $xml = $this->loadXml($copy);
        } else {
            $xml = \PHPUnit\TextUI\XmlConfiguration\DefaultConfiguration::create();
        }
        $configuration = $this->initRegistry($cli, $xml);
        $this->outcomes->readFailOn($configuration);
        $this->phpHandler($configuration);
        if ($configuration->hasBootstrap()) {
            $this->loadBootstrap($configuration->bootstrap());
        }
        $facade = \PHPUnit\Event\Facade::instance();
        Events::register($facade, $this->outcomes);
        $facade->registerTracer($this->reports);
        $this->bootExtensions($configuration);
        $this->registerLoggers($configuration);
        $this->reports->facade()->seal();
        \PHPUnit\TestRunner\TestResult\Facade::init();
        \PHPUnit\Event\Facade::instance()->seal();
        $suite = $this->buildSuite($configuration);
        $this->cases = $this->collect($configuration, $suite);
        $this->rememberClassSuites($suite);
        if ($this->cases === []) {
            $status = $this->outcomes->exitStatus();
            if ($status !== 0) {
                exit($status);
            }
            fwrite(STDERR, "Selective did not discover any tests.\n");
            exit(1);
        }
        $emitter = \PHPUnit\Event\Facade::emitter();
        $emitter->testRunnerStarted();
        $emitter->testRunnerExecutionStarted(\PHPUnit\Event\TestSuite\TestSuiteBuilder::from($suite));
        $this->started = true;
    }

    public function manifestCases(): array
    {
        $rows = [];
        foreach ($this->order as $id) {
            $case = $this->cases[$id];
            $rows[] = [
                'id' => $case['id'],
                'file_path' => $case['file_path'],
                'line_number' => $case['line_number'],
                'description' => $case['description'],
                'full_description' => $case['full_description'],
                'run_time' => 0.0,
            ];
        }

        return $rows;
    }

    public function runAssigned(string $id, bool $record): array
    {
        $this->outcomes->beginAttempt();
        $case = $this->cases[$id] ?? null;
        if ($case === null) {
            return $this->outcomes->uncollected($id);
        }
        $this->reports->begin($id);
        $started = hrtime(true);
        $this->execute($case, $record);
        $seconds = (hrtime(true) - $started) / 1e9;
        $this->reports->end();

        return $this->outcomes->commit($case['phpunit_id'], $case, $seconds);
    }

    public function finish(): void
    {
        if (!$this->started) {
            return;
        }
        $this->reports->finishing();
        $emitter = \PHPUnit\Event\Facade::emitter();
        $emitter->testRunnerExecutionFinished();
        $emitter->testRunnerFinished();
        $this->reports->write();
        $this->started = false;
    }

    public function fragment(): ?array
    {
        if ($this->traces === []) {
            return null;
        }
        $files = [];
        $index = [];
        $tests = [];
        foreach ($this->traces as $id => $paths) {
            $deps = [];
            foreach (array_keys($paths) as $path) {
                if (!isset($index[$path])) {
                    $index[$path] = count($files);
                    $files[] = $path;
                }
                $deps[] = $index[$path];
            }
            $tests[] = ['id' => $id, 'deps' => $deps];
        }

        return [
            'version' => 1,
            'files' => $files,
            'tests' => $tests,
        ];
    }

    public function remove(string $id): void
    {
        $this->outcomes->remove($id);
        $this->reports->remove($id);
    }

    public function outcomes(): Outcomes
    {
        return $this->outcomes;
    }

    public function exitStatus(): int
    {
        return $this->outcomes->exitStatus();
    }

    public function hookReport(): array
    {
        return $this->outcomes->hookReport();
    }

    private function execute(array $case, bool $record): void
    {
        if (($case['phpt'] ?? '') !== '') {
            $this->runPhpt($case);

            return;
        }
        $class = (string) $case['class'];
        $tracing = false;
        try {
            $suite = $this->classSuite($class);
            if (!$this->narrow($suite, (string) $case['phpunit_id'])) {
                return;
            }
            if ($record && $this->tracer->available()) {
                $this->tracer->start((string) $case['id']);
                $tracing = true;
            }
            $this->outcomes->running((string) $case['phpunit_id']);
            $suite->run();
        } catch (\Throwable $error) {
            $this->outcomes->note(
                (string) $case['phpunit_id'],
                'failed',
                $error->getMessage(),
                [$error->getMessage()],
                [$case['file_path'] . ':' . $case['line_number'] . ': ' . $error->getMessage()]
            );
            $this->outcomes->issue((string) $case['phpunit_id'], 'error');
        }
        if ($tracing) {
            $seen = $this->traces[$case['id']] ?? [];
            foreach ($this->tracer->stop() as $path) {
                $seen[$path] = true;
            }
            $own = $this->tracer->normalize((string) $case['file_path']);
            if ($own !== null) {
                $seen[$own] = true;
            }
            $this->traces[$case['id']] = $seen;
        }
    }

    private function runPhpt(array $case): void
    {
        $class = class_exists(\PHPUnit\Runner\Phpt\TestCase::class)
            ? \PHPUnit\Runner\Phpt\TestCase::class
            : \PHPUnit\Runner\PhptTestCase::class;
        $test = new $class((string) $case['phpt']);
        $this->outcomes->running((string) $case['phpunit_id']);
        $test->run();
    }

    private function classSuite(string $class): TestSuite
    {
        $this->built[$class] ??= $this->fromClass(new \ReflectionClass($class));

        return clone $this->built[$class];
    }

    private function rememberClassSuites(TestSuite $suite): void
    {
        foreach ($suite->tests() as $test) {
            if (!$test instanceof TestSuite) {
                continue;
            }
            if ($test->isForTestClass()) {
                $this->built[$test->name()] = $test;
            } else {
                $this->rememberClassSuites($test);
            }
        }
    }

    private function fresh(TestCase $test): TestCase
    {
        $fresh = clone $test;
        foreach ((new \ReflectionClass(TestCase::class))->getProperties() as $property) {
            if ($property->isStatic() || !$property->isInitialized($fresh)) {
                continue;
            }
            $value = $property->getValue($fresh);
            if (is_object($value) && str_starts_with($value::class, self::PER_RUN_STATE_NAMESPACE)) {
                $property->setValue($fresh, clone $value);
            }
        }

        return $fresh;
    }

    private function find(TestSuite $suite, string $phpunitId): ?object
    {
        foreach (new \RecursiveIteratorIterator($suite) as $test) {
            if (!method_exists($test, 'valueObjectForEvents')) {
                continue;
            }
            if ($test->valueObjectForEvents()->id() === $phpunitId) {
                return $test;
            }
        }

        return null;
    }

    private function narrow(TestSuite $suite, string $id): bool
    {
        $found = $this->find($suite, $id);
        if (!$found instanceof TestCase) {
            return false;
        }
        $fresh = $this->fresh($found);
        $this->satisfy($fresh);
        $suite->setTests([$fresh]);

        return true;
    }

    private function satisfy(TestCase $test): void
    {
        foreach ($test->requires() as $dependency) {
            if (!$dependency->isValid()) {
                continue;
            }
            if ($dependency->targetIsClass()) {
                $class = $dependency->getTargetClassName();
                $this->runDependency($class . '::class', $class, static fn (): bool => true);
                continue;
            }
            $target = $dependency->getTarget();
            if (!$this->methodPassed($target)) {
                $this->runDependency($target, (string) strstr($target, '::', true), fn (TestCase $test): bool => $this->isTarget($test, $target));
            }
        }
    }

    private function runDependency(string $key, string $class, \Closure $wanted): void
    {
        if (isset($this->satisfied[$key]) || $class === '' || !class_exists($class)) {
            return;
        }
        $this->satisfied[$key] = true;
        $source = $this->classSuite($class);
        $ids = $this->idsIn($source, $wanted);
        if ($ids === []) {
            $source = $this->fromClass(new \ReflectionClass($class));
            $ids = $this->idsIn($source, $wanted);
        }
        $passed = $ids !== [];
        $samples = [];
        foreach ($ids as $id) {
            $this->outcomes->running($id);
            try {
                $one = clone $source;
                if (!$this->narrow($one, $id)) {
                    $passed = false;
                    continue;
                }
                $one->run();
            } catch (\Throwable $error) {
                $this->outcomes->note(
                    $id,
                    'failed',
                    $error->getMessage(),
                    [$error->getMessage()],
                    [$id . ': ' . $error->getMessage()]
                );
                $this->outcomes->issue($id, 'error');
            }
            if (($this->outcomes->attempt[$id]['status'] ?? null) !== 'passed') {
                $passed = false;
                continue;
            }
            $found = $this->find($source, $id);
            $value = $found instanceof TestCase ? $found->valueObjectForEvents() : null;
            if ($value instanceof \PHPUnit\Event\Code\TestMethod) {
                $samples[$value->className() . '::' . $value->methodName()] = $value;
            }
        }
        if (!$passed) {
            unset($this->satisfied[$key]);

            return;
        }
        $recorded = \PHPUnit\TestRunner\TestResult\PassedTests::instance();
        foreach ($samples as $method => $value) {
            if (!$recorded->hasTestMethodPassed($method)) {
                $recorded->testMethodPassed($value, null);
            }
        }
    }

    private function idsIn(TestSuite $suite, \Closure $wanted): array
    {
        $ids = [];
        foreach (new \RecursiveIteratorIterator($suite) as $test) {
            if ($test instanceof TestCase && $wanted($test)) {
                $ids[] = $test->valueObjectForEvents()->id();
            }
        }

        return $ids;
    }

    private function isTarget(TestCase $test, string $target): bool
    {
        $id = $test->valueObjectForEvents()->id();

        return $id === $target || str_starts_with($id, $target . '#');
    }

    private function methodPassed(string $target): bool
    {
        return \PHPUnit\TestRunner\TestResult\PassedTests::instance()->hasTestMethodPassed($target);
    }

    private function fromClass(\ReflectionClass $class): TestSuite
    {
        if ((new \ReflectionMethod(TestSuite::class, 'fromClassReflector'))->getNumberOfRequiredParameters() > 1) {
            return TestSuite::fromClassReflector($class, \PHPUnit\Event\Facade::emitter());
        }

        return TestSuite::fromClassReflector($class);
    }

    private function collect(object $configuration, TestSuite $suite): array
    {
        $tests = $this->filteredTests($configuration, $suite);
        $objects = [];
        foreach ($tests as $test) {
            $value = $test->valueObjectForEvents();
            $isCase = $test instanceof TestCase;
            $objects[$value->id()] = [
                'class' => $isCase ? $test::class : '',
                'method' => $isCase ? $value->methodName() : '',
                'file' => Paths::relative($value->file()),
                'line' => $isCase ? $value->line() : 1,
                'phpt' => $isCase ? '' : $value->file(),
            ];
        }
        $xml = $this->writeListXml($tests);
        $parsed = [];
        if (is_string($xml)) {
            $body = file_get_contents($xml);
            if (is_string($body)) {
                $parsed = ListTestsXml::parse($body, Version::phpunitMajor());
            }
            @unlink($xml);
        }
        $cases = [];
        $this->order = [];
        $pool = $objects;
        foreach ($parsed as $row) {
            $taken = $this->take($pool, (string) $row['id']);
            if ($taken === null) {
                continue;
            }
            $object = $taken['object'];
            if ($object['file'] === '' && (string) $row['file'] !== '') {
                $object['file'] = Paths::relative((string) $row['file']);
            }
            $this->remember($cases, $taken['id'], $object);
        }
        foreach ($pool as $phpunitId => $object) {
            $this->remember($cases, (string) $phpunitId, $object);
        }

        return $cases;
    }

    private function take(array &$pool, string $id): ?array
    {
        if (isset($pool[$id])) {
            $object = $pool[$id];
            unset($pool[$id]);

            return ['id' => $id, 'object' => $object];
        }
        $wanted = str_contains($id, "\0") ? false : realpath($id);
        foreach ($pool as $key => $object) {
            $candidate = (string) (($object['phpt'] ?? '') !== '' ? $object['phpt'] : $key);
            $have = str_contains($candidate, "\0") ? false : realpath($candidate);
            if ($wanted !== false && $have !== false && $wanted === $have) {
                unset($pool[$key]);

                return ['id' => (string) $key, 'object' => $object];
            }
        }

        return null;
    }

    private function remember(array &$cases, string $phpunitId, array $object): void
    {
        $id = ShortId::apply($phpunitId);
        $dataset = '';
        $marker = strpos($phpunitId, '#');
        if ($marker !== false) {
            $dataset = substr($phpunitId, $marker);
        }
        $description = (string) $object['method'];
        if ($dataset !== '') {
            $description .= $dataset;
        }
        $full = (string) $object['class'];
        if ($full !== '' && $description !== '') {
            $full .= '::' . $description;
        } elseif ($full === '') {
            $full = $phpunitId;
        }
        $file = (string) $object['file'];
        $cases[$id] = [
            'id' => $id,
            'phpunit_id' => $phpunitId,
            'class' => (string) $object['class'],
            'method' => (string) $object['method'],
            'description' => $description === '' ? $id : $description,
            'full_description' => $full,
            'file_path' => $file,
            'line_number' => max(1, (int) $object['line']),
            'phpt' => (string) ($object['phpt'] ?? ''),
        ];
        $this->order[] = $id;
    }

    private function writeListXml(array $tests): ?string
    {
        $class = \PHPUnit\TextUI\Command\ListTestsAsXmlCommand::class;
        $file = tempnam(sys_get_temp_dir(), 'sel-list-');
        if ($file === false) {
            return null;
        }
        $constructor = (new \ReflectionClass($class))->getConstructor();
        $first = $constructor?->getParameters()[0] ?? null;
        $type = $first?->getType();
        $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : '';
        if ($typeName === 'string' || $first?->getName() === 'filename') {
            $flat = TestSuite::empty('selective');
            foreach ($tests as $test) {
                $flat->addTest($test);
            }
            $command = new $class($file, $flat);
        } else {
            $command = new $class(array_values($tests), $file);
        }
        $command->execute();

        return $file;
    }

    private function filteredTests(object $configuration, TestSuite $suite): array
    {
        $this->make(\PHPUnit\TextUI\TestSuiteFilterProcessor::class)->process($configuration, $suite);
        if (method_exists($suite, 'collect')) {
            return $suite->collect();
        }

        return $this->leaves($suite);
    }

    private function leaves(TestSuite $suite): array
    {
        $tests = [];
        foreach ($suite as $test) {
            if ($test instanceof TestSuite) {
                foreach ($this->leaves($test) as $child) {
                    $tests[] = $child;
                }
                continue;
            }
            $tests[] = $test;
        }

        return $tests;
    }

    private function registerLoggers(object $configuration): void
    {
        $facade = $this->reports->facade();
        foreach (['hasCoverageReport' => 'code coverage reports', 'hasLogfileTestdoxHtml' => '--testdox-html', 'hasLogfileTestdoxText' => '--testdox-text', 'outputIsTestDox' => '--testdox'] as $method => $what) {
            if ($configuration->{$method}()) {
                fwrite(STDERR, "Selective does not produce {$what}; this run writes none.\n");
            }
        }
        if ($configuration->hasLogfileJunit()) {
            try {
                new \PHPUnit\Logging\JUnit\JunitXmlLogger(
                    \PHPUnit\TextUI\Output\Facade::printerFor($configuration->logfileJunit()),
                    $facade
                );
            } catch (\Throwable $error) {
                \PHPUnit\Event\Facade::emitter()->testRunnerTriggeredPhpunitWarning($error->getMessage());
            }
        }
        if ($configuration->hasLogfileTeamcity()) {
            try {
                new \PHPUnit\Logging\TeamCity\TeamCityLogger(
                    \PHPUnit\TextUI\Output\DefaultPrinter::from($configuration->logfileTeamcity()),
                    $facade
                );
            } catch (\Throwable $error) {
                \PHPUnit\Event\Facade::emitter()->testRunnerTriggeredPhpunitWarning($error->getMessage());
            }
        }
    }

    private function buildSuite(object $configuration): TestSuite
    {
        return $this->make(\PHPUnit\TextUI\Configuration\TestSuiteBuilder::class)->build($configuration);
    }

    private function bootExtensions(object $configuration): void
    {
        $facadeClass = class_exists(\PHPUnit\Runner\Extension\Facade::class)
            ? \PHPUnit\Runner\Extension\Facade::class
            : \PHPUnit\Runner\Extension\ExtensionFacade::class;
        $facade = new $facadeClass();
        $reflection = new \ReflectionClass(\PHPUnit\Runner\Extension\ExtensionBootstrapper::class);
        $args = [$configuration, $facade];
        $constructor = $reflection->getConstructor();
        if ($constructor && $constructor->getNumberOfParameters() >= 3) {
            $args[] = \PHPUnit\Event\Facade::emitter();
        }
        $bootstrapper = $reflection->newInstanceArgs($args);
        foreach ($configuration->extensionBootstrappers() as $extension) {
            $bootstrapper->bootstrap($extension['className'], $extension['parameters']);
        }
    }

    private function loadBootstrap(string $filename): void
    {
        if (!is_readable($filename)) {
            fwrite(STDERR, "Cannot open bootstrap script \"{$filename}\"\n");
            exit(1);
        }
        try {
            include_once $filename;
        } catch (\Throwable $error) {
            fwrite(STDERR, 'Error in bootstrap script: ' . $error::class . ' ' . $error->getMessage() . "\n");
            exit(1);
        }
        $emitter = \PHPUnit\Event\Facade::emitter();
        if (method_exists($emitter, 'testRunnerBootstrapFinished')) {
            $emitter->testRunnerBootstrapFinished($filename);
        }
    }

    private function phpHandler(object $configuration): void
    {
        $this->make(\PHPUnit\TextUI\Configuration\PhpHandler::class)->handle($configuration->php());
    }

    private function initRegistry(object $cli, object $xml): object
    {
        $method = new \ReflectionMethod(\PHPUnit\TextUI\Configuration\Registry::class, 'init');
        $args = [$cli, $xml];
        if ($method->getNumberOfParameters() >= 3) {
            $args[] = \PHPUnit\Event\Facade::emitter();
        }

        return $method->invoke(null, ...$args);
    }

    private function loadXml(string $file): object
    {
        return $this->make(\PHPUnit\TextUI\XmlConfiguration\Loader::class)->load($file);
    }

    private function cli(array $args): object
    {
        return $this->make(\PHPUnit\TextUI\CliArguments\Builder::class)->fromParameters(array_merge(['phpunit'], $args));
    }

    private function make(string $class): object
    {
        $reflection = new \ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        if ($constructor && $constructor->getNumberOfRequiredParameters() > 0) {
            return $reflection->newInstance(\PHPUnit\Event\Facade::emitter());
        }

        return $reflection->newInstance();
    }
}
