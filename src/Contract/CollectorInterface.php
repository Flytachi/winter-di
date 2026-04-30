<?php

declare(strict_types=1);

namespace Flytachi\Winter\DI\Contract;

use ReflectionClass;

/**
 * Receives each discovered class during a Scanner pass.
 *
 * Implement this interface to plug custom logic into Scanner::run():
 *   - DICollector        — registers #[Singleton], #[Request], #[Transient]
 *   - MappingCollector   — registers route attributes (#[GetMapping] …)
 *   - ExceptionCollector — registers #[AdviceException] handlers
 *   - PpaCollector       — registers repository declarations
 *   - CmdCollector       — discovers console commands
 *
 * Each collect() call receives one class. Heavy work (instantiation, binding)
 * should be deferred — collect() is called in a tight loop over potentially
 * hundreds of classes.
 */
interface CollectorInterface
{
    /**
     * Process a single discovered class.
     *
     * @param class-string   $class  Fully-qualified class name
     * @param ReflectionClass $ref   Reflection instance (attributes, parents, interfaces)
     */
    public function collect(string $class, ReflectionClass $ref): void;
}
