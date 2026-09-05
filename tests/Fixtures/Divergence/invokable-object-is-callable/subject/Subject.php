<?php

declare(strict_types=1);

namespace Divergence\InvokableObjectIsCallable;

interface Processor
{
    public function __invoke(string $record): string;
}

final class Subject
{
    /**
     * A union of a callable and an interface that declares `__invoke`, called with no narrowing.
     *
     * The shape at `monolog` `Handler/ProcessableHandlerTrait.php:56` and `Logger.php:378`, where the
     * property is `@phpstan-var array<(callable(LogRecord): LogRecord)|ProcessorInterface>`. PHPStan treats
     * an object whose class declares `__invoke` as callable and exempts the call; the port has to ask the
     * codebase the same question, because mago reports the union identically whether the class is invokable
     * or not.
     *
     * @var array<(callable(string): string)|Processor>
     */
    private array $processors = [];

    public function run(string $record): string
    {
        foreach ($this->processors as $processor) {
            $record = $processor($record);
        }

        return $record;
    }

    /** The control: a dynamic call on a plain string, which both engines report. */
    public function plain(string $other): void
    {
        $other();
    }
}
